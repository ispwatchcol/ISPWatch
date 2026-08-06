<?php

namespace App\Http\Controllers;

use App\Models\AdditionalService;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de servicios adicionales reutilizables (alquiler de router extra,
 * soporte técnico mensual...).
 *
 * Es la plantilla: nombre, precio y las dos reglas de cobro. Quién lo tiene y
 * desde cuándo vive en customer_additional_services, y el cobro en sí entra en
 * la mensualidad del cliente — nunca en factura aparte.
 *
 * El tenant sale SIEMPRE del usuario autenticado (el trait BelongsToTenant lo
 * pone al crear y filtra al leer); nunca del payload.
 */
class AdditionalServiceController extends Controller
{
    public function __construct(protected BillingService $billingService)
    {
    }

    /**
     * Asignaciones que debían cobrarse este mes y no aparecen en ninguna
     * factura. Es el aviso de fuga silenciosa: sin él, un servicio puede
     * quedarse meses "activo" en la ficha sin que nadie lo esté facturando.
     */
    public function unbilled(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        if (!$tenantId) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        $items = $this->billingService->unbilledAdditionalServices((int) $tenantId)
            ->map(fn ($a) => [
                'id'            => $a->id,
                'customer_id'   => $a->customer_id,
                'customer_name' => trim(($a->customer?->customerProfile?->name ?? '')
                    . ' ' . ($a->customer?->customerProfile?->last_name ?? '')) ?: "Cliente #{$a->customer_id}",
                'service_name'  => $a->service?->name ?? '—',
                'amount'        => $a->effective_price * max(1, (int) $a->quantity),
            ])
            ->values();

        return response()->json([
            'count' => $items->count(),
            'total' => (float) $items->sum('amount'),
            'items' => $items,
        ]);
    }

    public function index(Request $request)
    {
        $services = AdditionalService::query()
            ->withCount('activeAssignments')
            ->ordered()
            ->get();

        return response()->json($services);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($this->nameTaken($request, $data['name'])) {
            return response()->json([
                'message' => 'Ya existe un servicio adicional con ese nombre.',
            ], 422);
        }

        return response()->json(AdditionalService::create($data), 201);
    }

    public function update(Request $request, int $id)
    {
        $service = $this->findOwn($id);

        if (!$service) {
            return response()->json(['message' => 'Servicio adicional no encontrado.'], 404);
        }

        $data = $this->validated($request, $service);

        if (isset($data['name']) && $this->nameTaken($request, $data['name'], $service->id)) {
            return response()->json([
                'message' => 'Ya existe un servicio adicional con ese nombre.',
            ], 422);
        }

        $service->update($data);

        return response()->json($service->fresh()->loadCount('activeAssignments'));
    }

    public function destroy(int $id)
    {
        $service = $this->findOwn($id);

        if (!$service) {
            return response()->json(['message' => 'Servicio adicional no encontrado.'], 404);
        }

        // Borrarlo dejaría sin explicación las facturas que ya lo cobraron: los
        // ítems apuntan a la asignación, y la asignación a este servicio.
        // Desactivar conserva el historial y lo saca de los desplegables igual.
        $asignaciones = $service->assignments()->count();

        if ($asignaciones > 0) {
            return response()->json([
                'message' => "Este servicio está asignado a {$asignaciones} cliente(s). Desactívalo en lugar de "
                    . 'eliminarlo para que las facturas ya emitidas conserven su historial.',
            ], 422);
        }

        $service->delete();

        return response()->json(['message' => 'Servicio adicional eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * En `update` los campos son opcionales (`sometimes`), así que un PATCH
     * parcial no borra lo que no venga en el payload.
     */
    private function validated(Request $request, ?AdditionalService $existing = null): array
    {
        $required = $existing ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'                     => "{$required}|string|max:120",
            'price'                    => "{$required}|numeric|min:0",
            'description'              => 'nullable|string|max:255',
            'charge_on_courtesy_month' => 'boolean',
            'proration_mode'           => ['sometimes', Rule::in(AdditionalService::PRORATION_MODES)],
            'is_active'                => 'boolean',
            'sort_order'               => 'integer|min:0',
        ]);
    }

    /**
     * Nombre repetido dentro del mismo tenant, sin distinguir mayúsculas.
     *
     * `lower()` a pelo y no la macro whereLike: ésa añade comodines (`%`) y
     * buscaría "contiene", cuando aquí hace falta igualdad exacta. lower()
     * existe igual en PostgreSQL y en SQLite.
     */
    private function nameTaken(Request $request, string $name, ?int $exceptId = null): bool
    {
        $query = AdditionalService::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    /**
     * El scope global de BelongsToTenant ya deja fuera los de otros tenants, así
     * que un id ajeno cae aquí como "no encontrado".
     */
    private function findOwn(int $id): ?AdditionalService
    {
        return AdditionalService::find($id);
    }
}
