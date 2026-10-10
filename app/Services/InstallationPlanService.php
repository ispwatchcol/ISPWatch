<?php

namespace App\Services;

use App\Models\CustomerInstallation;
use App\Models\InstallationPlannedItem;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El plan de una instalación: qué productos del inventario se prevé llevar.
 *
 * PLANIFICAR NO MUEVE EXISTENCIAS. Nada de aquí pasa por InventoryLedger: no
 * descuenta, no reserva y no escribe en el kardex. Se puede planificar más de
 * lo que hay —el servidor lo acepta y devuelve un aviso—, porque el saldo que
 * manda es el del momento de USAR el material, y ése lo vuelve a validar el
 * ledger con sus reglas de permiso, origen y cantidad.
 *
 * La sincronización conserva las líneas existentes por `id`: una línea que ya
 * estaba se actualiza en cantidad y notas pero conserva su etiqueta y unidad
 * congeladas. Borrar y recrear todo en cada guardado reescribiría el nombre
 * con el del catálogo de hoy y el plan dejaría de decir lo que se planificó.
 */
class InstallationPlanService
{
    public function __construct(private InventoryAvailability $availability)
    {
    }

    /**
     * Reglas de validación del bloque `planned_items`. El producto tiene que
     * ser del tenant: `exists` usa el query builder y se salta el scope global
     * (ver § 82), así que el `where('tenant_id')` es lo que acota de verdad.
     *
     * @return array<string, mixed>
     */
    public function rules(int $tenantId): array
    {
        return [
            'planned_items'            => 'sometimes|nullable|array|max:50',
            'planned_items.*.id'       => 'nullable|integer',
            'planned_items.*.stock_id' => [
                'nullable', 'integer',
                Rule::exists('inventory_stock', 'id')->where('tenant_id', $tenantId),
            ],
            'planned_items.*.quantity' => 'required|numeric|min:0.01|max:1000000',
            'planned_items.*.notes'    => 'nullable|string|max:255',
        ];
    }

    /**
     * Deja el plan de la orden igual a `$items` y devuelve los avisos de
     * disponibilidad. Si `$items` es null no toca nada: un cliente que no manda
     * la clave (una pantalla anterior, la API) no borra el plan por omisión.
     *
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<int, string>
     */
    public function sync(CustomerInstallation $installation, ?array $items, User $actor): array
    {
        if ($items === null) {
            return [];
        }

        $existing = $installation->plannedItems()->get()->keyBy('id');
        $plan     = $this->normalize($installation, $items, $existing);

        if ($installation->isSigned() && $this->changes($plan, $existing)) {
            throw ValidationException::withMessages([
                'planned_items' => 'La orden ya está firmada: el plan de equipos y materiales no se modifica. '
                    . 'Lo firmado es lo que se usó en la visita.',
            ]);
        }

        DB::transaction(function () use ($installation, $plan, $existing, $actor) {
            $keep = [];

            foreach ($plan as $line) {
                if ($line['id'] !== null) {
                    $row = $existing->get($line['id']);
                    $row->quantity = $line['quantity'];
                    $row->notes    = $line['notes'];
                    $row->save();
                    $keep[] = $row->id;
                    continue;
                }

                $row = new InstallationPlannedItem([
                    'stock_id'      => $line['stock']->id,
                    'label'         => $line['stock']->label(),
                    'unit'          => $line['stock']->unit,
                    'is_serialized' => (bool) $line['stock']->is_serialized,
                    'quantity'      => $line['quantity'],
                    'notes'         => $line['notes'],
                    'created_by'    => $actor->id,
                ]);
                $row->tenant_id       = $installation->tenant_id;
                $row->installation_id = $installation->id;
                $row->save();
                $keep[] = $row->id;
            }

            $installation->plannedItems()->whereNotIn('id', $keep ?: [0])->delete();
        });

        $installation->unsetRelation('plannedItems');

        return $this->warnings($installation);
    }

    /**
     * Valida cada línea contra la orden y el catálogo y la deja en forma
     * canónica. Una línea con `id` tiene que ser de ESTA orden; una sin `id`
     * necesita un producto del tenant.
     *
     * @return array<int, array{id: int|null, stock: InventoryStock|null, quantity: float, notes: string|null}>
     */
    private function normalize(CustomerInstallation $installation, array $items, $existing): array
    {
        $plan = [];

        foreach (array_values($items) as $i => $item) {
            $id       = isset($item['id']) ? (int) $item['id'] : null;
            $quantity = round((float) $item['quantity'], 2);
            $notes    = trim((string) ($item['notes'] ?? '')) ?: null;

            if ($id !== null) {
                $row = $existing->get($id);

                if (!$row) {
                    throw ValidationException::withMessages([
                        "planned_items.{$i}.id" => 'Esa línea del plan no pertenece a esta orden.',
                    ]);
                }

                $this->assertWholeUnits((bool) $row->is_serialized, $quantity, $row->label, $i);

                $plan[] = ['id' => $id, 'stock' => null, 'quantity' => $quantity, 'notes' => $notes];
                continue;
            }

            if (empty($item['stock_id'])) {
                throw ValidationException::withMessages([
                    "planned_items.{$i}.stock_id" => 'Elige el producto del inventario que se prevé usar.',
                ]);
            }

            $stock = InventoryStock::withoutTenantScope()
                ->where('tenant_id', $installation->tenant_id)
                ->findOrFail((int) $item['stock_id']);

            $this->assertWholeUnits((bool) $stock->is_serialized, $quantity, $stock->label(), $i);

            $plan[] = ['id' => null, 'stock' => $stock, 'quantity' => $quantity, 'notes' => $notes];
        }

        return $plan;
    }

    /** Un equipo con serial se cuenta por unidades: «1,5 routers» no existe. */
    private function assertWholeUnits(bool $serialized, float $quantity, string $label, int $i): void
    {
        if ($serialized && floor($quantity) !== $quantity) {
            throw ValidationException::withMessages([
                "planned_items.{$i}.quantity" => "{$label} se maneja por serial: planifica unidades enteras.",
            ]);
        }
    }

    /** ¿El plan recibido difiere del guardado? */
    private function changes(array $plan, $existing): bool
    {
        if (count($plan) !== $existing->count()) {
            return true;
        }

        foreach ($plan as $line) {
            $row = $line['id'] !== null ? $existing->get($line['id']) : null;

            if (!$row
                || round((float) $row->quantity, 2) !== $line['quantity']
                || ($row->notes ?: null) !== $line['notes']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Avisos de lo planificado que supera lo que hay en TODO el tenant. No
     * bloquea: el inventario de hoy no es el del día de la visita.
     *
     * @return array<int, string>
     */
    public function warnings(CustomerInstallation $installation): array
    {
        $rows = $installation->plannedItems()->whereNotNull('stock_id')->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $available = $this->availability->availableByStock((int) $installation->tenant_id);
        $avisos    = [];

        foreach ($rows->groupBy('stock_id') as $stockId => $lines) {
            $planned = (float) $lines->sum(fn ($l) => (float) $l->quantity);
            $hay     = (float) ($available[(int) $stockId] ?? 0);

            if ($planned > $hay) {
                $first = $lines->first();
                $unit  = $first->unit ? " {$first->unit}" : '';
                $avisos[] = "Planificas {$this->qty($planned)}{$unit} de {$first->label} y en todo el inventario "
                    . "hay {$this->qty($hay)}{$unit}. Planificar no reserva: al usarlo se validará el saldo "
                    . 'de quien lo aporte.';
            }
        }

        return $avisos;
    }

    /**
     * Forma pública de las líneas. Sin precios: el plan lo ven también
     * técnicos que no tienen permiso de cartera ni de inventario.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(CustomerInstallation $installation): array
    {
        return $installation->plannedItems
            ->sortBy('id')
            ->map(fn (InstallationPlannedItem $item) => [
                'id'            => $item->id,
                'stock_id'      => $item->stock_id,
                'label'         => $item->label,
                'unit'          => $item->unit,
                'is_serialized' => (bool) $item->is_serialized,
                'quantity'      => (float) $item->quantity,
                'notes'         => $item->notes,
            ])
            ->values()
            ->all();
    }

    private function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}
