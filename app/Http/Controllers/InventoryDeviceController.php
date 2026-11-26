<?php

namespace App\Http\Controllers;

use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\TicketEquipment;
use App\Models\User;
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
            // Para un equipo instalado en la orden de un prospecto: el nombre
            // sólo está en la orden → prospecto (customer_id sigue en null).
            // Columnas calificadas: latestOfMany() hace un join consigo misma y
            // un «device_id» a secas es ambiguo.
            'latestInstallationLine:installation_equipment.id,installation_equipment.device_id,installation_equipment.installation_id',
            'latestInstallationLine.installation:id,customer_id,prospect_id',
            'latestInstallationLine.installation.prospect:id,name,last_name',
        ])
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['holder_id'] ?? null, fn ($q, $v) => $q->heldByUser((int) $v))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (InventoryDevice $device) {
                $installedFor = $this->installedFor($device);

                $row = $device->toArray();
                unset($row['latest_installation_line']);
                $row['installed_for'] = $installedFor;
                $row['holder_label']  = $this->holderLabel($device, $installedFor);

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

        // El alta es el primer movimiento del kardex: sin él, el historial de un
        // equipo empezaría en su primer traspaso y no se sabría de dónde salió.
        // Van juntos: un equipo sin su entrada (o una entrada sin equipo) era
        // posible si el segundo paso fallaba, y el ingreso por lotes llama esto
        // una vez por unidad.
        $device = DB::transaction(function () use ($data, $request) {
            $device = InventoryDevice::create($data);
            $this->ledger->recordInitialEntry($device, $request->user());

            return $device;
        });

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

        // `exists:tabla,id` a secas no mira el tenant: aceptaba el modelo, el
        // proveedor, la bodega o la persona de otra empresa como custodio del
        // equipo propio.
        $delTenant = fn (string $tabla) => Rule::exists($tabla, 'id')->where('tenant_id', $tenantId);

        return [
            'stock_id'    => ['nullable', 'integer', $delTenant('inventory_stock'), $this->onlySerializedStock($device)],
            'provider_id' => ['nullable', 'integer', $delTenant('inventory_provider')],
            'user_id'     => ['nullable', 'integer', $delTenant('users')],
            'branch_id'   => ['nullable', 'integer', $delTenant('inventory_branch')],
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
    /**
     * Un equipo con serial sólo puede ser de un modelo «por serial».
     *
     * El formulario «Agregar equipo» listaba todo el catálogo, materiales
     * incluidos, y el servidor lo aceptaba: «Fibra drop» con un serial quedaba
     * como una fila de `inventory_device`, que para un material no cuenta como
     * metros (los metros son saldos), y la existencia del catálogo sumaba esa
     * fila como si fuera un metro más. El material se da de alta en Stock como
     * «Por cantidad» y su existencia entra por Entregas y traspasos → Entrada
     * de material.
     *
     * Al editar, sólo se comprueba si CAMBIA el modelo: una fila vieja creada
     * así antes de esta regla tiene que poder abrirse y corregirse.
     */
    private function onlySerializedStock(?InventoryDevice $device): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($device) {
            if ($value === null || ($device && (int) $device->stock_id === (int) $value)) {
                return;
            }

            $stock = InventoryStock::find($value);

            if ($stock && !$stock->is_serialized) {
                $fail("«{$stock->label()}» es un material por cantidad: no lleva serial ni MAC. "
                    . 'Su existencia se registra en Inventarios → Ingresar al inventario → Material por cantidad.');
            }
        };
    }

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

    /**
     * En casa de quién está un equipo instalado: un cliente, o el prospecto de
     * la orden cuando todavía no se ha dado de alta como cliente. Antes la
     * segunda situación salía como «Instalado · cliente», sin nombre, y nadie
     * sabía dónde estaba el equipo. P-74.
     *
     * @return array{type: string, id: int|null, name: string|null, installation_id: int|null}|null
     */
    private function installedFor(InventoryDevice $device): ?array
    {
        if ($device->status !== InventoryDevice::STATUS_INSTALLED) {
            return null;
        }

        $installation = $device->latestInstallationLine?->installation;

        if ($device->customer_id) {
            return [
                'type'            => 'customer',
                'id'              => (int) $device->customer_id,
                'name'            => $this->userName($device->customer),
                'installation_id' => $installation?->id,
            ];
        }

        $prospect = $installation?->prospect;

        if ($prospect) {
            return [
                'type'            => 'prospect',
                'id'              => (int) $prospect->id,
                'name'            => trim(($prospect->name ?? '') . ' ' . ($prospect->last_name ?? '')) ?: null,
                'installation_id' => (int) $installation->id,
            ];
        }

        return ['type' => 'unknown', 'id' => null, 'name' => null, 'installation_id' => $installation?->id];
    }

    private function userName(?User $user): ?string
    {
        return $user
            ? (trim(($user->user_name ?? '') . ' ' . ($user->user_lastname ?? '')) ?: $user->name)
            : null;
    }

    /**
     * "Bodega Norte", "Juan Pérez", "Instalado · Cliente: María Gómez" o
     * "Instalado · Prospecto: Javier Gutiérrez (orden #72)".
     */
    private function holderLabel(InventoryDevice $device, ?array $installedFor = null): string
    {
        return match ($device->status) {
            InventoryDevice::STATUS_ASSIGNED  => $this->userName($device->holder) ?? 'Asignado',
            InventoryDevice::STATUS_INSTALLED => $this->installedLabel($installedFor),
            InventoryDevice::STATUS_RETIRED   => 'De baja',
            default                           => $device->branch?->name ?: 'Bodega',
        };
    }

    private function installedLabel(?array $for): string
    {
        $orden = !empty($for['installation_id']) ? " (orden #{$for['installation_id']})" : '';

        return match ($for['type'] ?? null) {
            'customer' => 'Instalado · Cliente: ' . ($for['name'] ?? "#{$for['id']}"),
            'prospect' => 'Instalado · Prospecto: ' . ($for['name'] ?? "#{$for['id']}") . $orden,
            default    => 'Instalado · sin cliente asignado' . $orden,
        };
    }
}
