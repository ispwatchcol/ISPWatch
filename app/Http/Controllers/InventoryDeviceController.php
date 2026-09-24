<?php

namespace App\Http\Controllers;

use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\TicketEquipment;
use App\Services\Inventory\InventoryExpenseRecorder;
use App\Services\Inventory\InventoryLedger;
use App\Support\InventoryIdentifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryDeviceController extends Controller
{
    public function __construct(
        private InventoryLedger $ledger,
        private InventoryExpenseRecorder $expenseRecorder,
    ) {
    }

    /**
     * Display a listing of the devices.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status'    => 'nullable|in:stock,assigned,installed,retired',
            'holder_id' => 'nullable|integer',
        ]);

        // Return devices with their nested stock/provider/branch relations so the
        // Inventory list can render brand/model/provider/branch names and ids.
        // Tenant scoping is automatic via BelongsToTenant.
        $devices = InventoryDevice::with([
            'stock:id,brand,model,price,is_serialized,unit',
            'provider:id,name',
            'branch:id,name',
            'holder:id,user_name,user_lastname,name',
            'customer:id,user_name,user_lastname,name',
        ])
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['holder_id'] ?? null, fn ($q, $v) => $q->heldByUser((int) $v))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (InventoryDevice $device) {
                $row = $device->toArray();
                $row['holder_label'] = $this->holderLabel($device);

                return $row;
            });

        return response()->json($devices);
    }

    /**
     * Store a newly created device in storage.
     */
    public function store(Request $request)
    {
        $this->normalizeIdentifiers($request);

        $data = $request->validate($this->rules($request), $this->messages());

        // Un equipo con custodio nace ya entregado; sin custodio, en bodega.
        $data['status'] = !empty($data['user_id'])
            ? InventoryDevice::STATUS_ASSIGNED
            : InventoryDevice::STATUS_STOCK;

        $device = InventoryDevice::create($data);

        // El alta es el primer movimiento del kardex: sin él, el historial de un
        // equipo empezaría en su primer traspaso y no se sabría de dónde salió.
        $this->ledger->recordInitialEntry($device, $request->user());

        return response()->json([
            'message' => 'Equipo añadido correctamente. ✅',
            'device' => $device
        ], 201);
    }

    /**
     * Display the specified device.
     */
    public function show(InventoryDevice $inventory)
    {
        return response()->json($inventory->load(['stock', 'provider', 'branch', 'holder', 'customer']));
    }

    /**
     * Update the specified device in storage.
     *
     * Cambiar "asignado a" desde el formulario es un traspaso como cualquier
     * otro, así que se delega en InventoryLedger en vez de escribir la columna
     * a mano: de lo contrario el equipo cambiaría de manos sin dejar rastro.
     */
    public function update(Request $request, InventoryDevice $inventory)
    {
        $this->normalizeIdentifiers($request);

        $data = $request->validate($this->rules($request, $inventory), $this->messages());

        $newUserId   = $data['user_id'] ?? null;
        $newBranchId = $data['branch_id'] ?? null;
        $custodyMoved = $inventory->status !== InventoryDevice::STATUS_INSTALLED
            && ((int) $newUserId !== (int) $inventory->user_id
                || (!$newUserId && (int) $newBranchId !== (int) $inventory->branch_id));

        // Las columnas de custodia las mueve el ledger, no el update directo.
        unset($data['user_id'], $data['branch_id']);
        $inventory->update($data);

        if ($custodyMoved) {
            $target = $newUserId ?: $newBranchId;

            $this->ledger->transferDevice(
                $inventory,
                $newUserId ? InventoryMovement::HOLDER_USER : InventoryMovement::HOLDER_BRANCH,
                $target === null ? null : (int) $target,
                $request->user(),
                'Cambio desde la ficha del equipo'
            );
        }

        return response()->json([
            'message' => 'Equipo actualizado correctamente. ✅',
            'device' => $inventory->fresh()
        ]);
    }

    /**
     * Remove the specified device from storage.
     *
     * DOS GUARDAS, Y CADA UNA RESPONDE UNA PREGUNTA DISTINTA.
     *
     * 1. ¿Está AHORA en casa de un cliente? Lo dice `inventory_device.status`,
     *    que es una sola fila por aparato. Para sacarlo del inventario está la
     *    baja (`/inventory/{id}/retire`), que sí queda escrita en el kardex.
     *
     * 2. ¿Hay algún documento que lo nombre y que perdería el serial si se
     *    borra? Las líneas de instalación y las de ticket apuntan al equipo con
     *    `SET NULL`, así que el DELETE no falla: deja el documento sin equipo y
     *    nadie vuelve a saber qué router quedó ahí.
     *
     * POR QUÉ LA PRIMERA GUARDA YA NO MIRA `installation_equipment`
     *
     * Mirarla era correcto mientras la ÚNICA forma de devolver un equipo fuera
     * BORRAR su línea de la hoja: si la fila existía, el aparato estaba puesto.
     * Desde que el retiro por ticket existe, esa línea se CONSERVA a propósito
     * —es el registro de una visita que sí ocurrió— y un equipo ya devuelto a
     * bodega seguía teniéndola. El guard lo rechazaba para siempre, con un
     * mensaje además falso —«está instalado en casa de un cliente»— y sin
     * salida posible: el operador YA lo había devuelto. El aparato quedaba
     * inservible para el resto de su vida útil sin que nadie entendiera por qué.
     *
     * Ahora «dónde está hoy» lo responde sólo `status`, que es quien lo sabe, y
     * el historial documental se protege aparte y con su propio mensaje.
     */
    public function destroy(InventoryDevice $inventory)
    {
        if ($inventory->status === InventoryDevice::STATUS_INSTALLED) {
            throw ValidationException::withMessages([
                'device' => 'Este equipo está instalado en casa de un cliente y no se puede eliminar. '
                    . 'Devuélvelo a bodega o dale de baja para sacarlo del inventario.',
            ]);
        }

        // Borrarlo dejaría la hoja de la visita sin equipo: `ticket_equipment`
        // lo referencia con `nullOnDelete`, así que el serial desaparecería del
        // expediente. El kardex conserva `device_serial` congelado, pero el
        // ticket no —y es el ticket el que se audita cuando el cliente reclama.
        //
        // withoutTenantScope: el equipo pudo moverse en el ticket de otra sede
        // del mismo grupo, y el rastro vale igual. La pregunta aquí es «¿alguien
        // lo nombra?», no «¿lo nombra alguien de los míos?».
        if (TicketEquipment::withoutTenantScope()->where('device_id', $inventory->id)->exists()) {
            throw ValidationException::withMessages([
                'device' => 'Este equipo se movió en la visita de al menos un ticket y borrarlo dejaría '
                    . 'esa hoja sin serial. Para sacarlo del inventario dale de baja: '
                    . 'queda en el kardex y el ticket conserva su historia.',
            ]);
        }

        // Si la entrada de este equipo generó un gasto automático (KAN-91), hay
        // que anularlo: borrar el equipo sin tocar el gasto deja el balance
        // cargando una compra que ya no existe en el inventario.
        //
        // Se anula, no se borra. Es precedente firme del proyecto: destruir un
        // registro de dinero deja el balance cuadrando por arte de magia y sin
        // rastro de qué pasó.
        $entradas = InventoryMovement::withoutTenantScope()
            ->where('device_id', $inventory->id)
            ->where('type', InventoryMovement::TYPE_ENTRADA)
            ->pluck('id');

        foreach ($entradas as $movimientoId) {
            $this->expenseRecorder->voidForMovement($movimientoId);
        }

        $inventory->delete();

        return response()->json([
            'message' => 'Equipo eliminado correctamente. ✅'
        ]);
    }

    /**
     * Reglas de alta y edición.
     *
     * El serial y la MAC son únicos DENTRO del tenant, no en toda la base: dos
     * empresas distintas pueden tener equipos con el mismo serial y la carga
     * masiva (InventoryImport) ya deduplica así. Sin el where, un serial ajeno
     * bloqueaba el alta con un "ya está en uso" que el cliente no podía explicar
     * porque ese equipo no aparecía por ningún lado en su inventario.
     */
    private function rules(Request $request, ?InventoryDevice $device = null): array
    {
        $tenantId = $request->user()?->tenant_id;

        return [
            'stock_id'    => 'nullable|integer|exists:inventory_stock,id',
            'provider_id' => 'nullable|integer|exists:inventory_provider,id',
            'user_id'     => 'nullable|integer|exists:users,id',
            'branch_id'   => 'nullable|integer|exists:inventory_branch,id',
            'serial'      => ['nullable', 'string', 'max:255', $this->uniqueIgnoringCase('serial', $tenantId, $device, 'Ya tienes otro equipo registrado con este serial.')],
            'mac'         => ['nullable', 'string', 'max:255', $this->uniqueIgnoringCase('mac', $tenantId, $device, 'Ya tienes otro equipo registrado con esta MAC.')],
        ];
    }

    /**
     * Espacios fuera antes de validar (KAN-100 · P-44).
     *
     * Se hace sobre el request y no después de validar para que la regla de
     * unicidad compare exactamente lo que se va a guardar: con un espacio final
     * colado, «SN-001 » pasaba la validación y entraba como un equipo distinto
     * de «SN-001».
     */
    private function normalizeIdentifiers(Request $request): void
    {
        foreach (['serial', 'mac'] as $campo) {
            if ($request->exists($campo)) {
                $request->merge([$campo => InventoryIdentifier::store($request->input($campo))]);
            }
        }
    }

    /**
     * Unicidad por tenant SIN distinguir mayúsculas (KAN-100 · P-44).
     *
     * No se usa `Rule::unique`: en PostgreSQL compara con `=`, que es sensible
     * a mayúsculas, y dejaba convivir `SN-001` con `sn-001` — dos filas para un
     * mismo equipo que después bloqueaban la carga masiva, que sí compara en
     * minúsculas. Esta regla iguala los dos caminos.
     *
     * `LOWER()` en la consulta es lo mismo que indexa la migración
     * 2026_09_21_000001, así que la validación y el índice único no pueden
     * discrepar.
     */
    private function uniqueIgnoringCase(string $column, ?int $tenantId, ?InventoryDevice $device, string $message): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($column, $tenantId, $device, $message): void {
            $comparable = InventoryIdentifier::comparable(is_string($value) ? $value : null);

            if ($comparable === null) {
                return;
            }

            $existe = DB::table('inventory_device')
                ->where('tenant_id', $tenantId)
                ->whereRaw("LOWER({$column}) = ?", [$comparable])
                ->when($device, fn ($query) => $query->where('id', '!=', $device->id))
                ->exists();

            if ($existe) {
                $fail($message);
            }
        };
    }

    /** En español y diciendo QUÉ campo choca: "status code 422" no le sirve a nadie. */
    private function messages(): array
    {
        // Los mensajes de unicidad los emite la propia regla (`uniqueIgnoringCase`):
        // al no ser ya la regla `unique` del framework, no tienen clave que
        // nombrar aquí.
        return [
            'serial.max'    => 'El serial no puede superar los 255 caracteres.',
            'mac.max'       => 'La MAC no puede superar los 255 caracteres.',
        ];
    }

    /** "Bodega Norte", "Juan Pérez" o "Instalado · María Gómez". */
    private function holderLabel(InventoryDevice $device): string
    {
        $name = fn ($user) => $user
            ? (trim(($user->user_name ?? '') . ' ' . ($user->user_lastname ?? '')) ?: $user->name)
            : null;

        return match ($device->status) {
            InventoryDevice::STATUS_ASSIGNED  => $name($device->holder) ?? 'Asignado',
            InventoryDevice::STATUS_INSTALLED => 'Instalado · ' . ($name($device->customer) ?? 'cliente'),
            InventoryDevice::STATUS_RETIRED   => 'De baja',
            default                           => $device->branch?->name ?: 'Bodega',
        };
    }
}
