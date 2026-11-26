<?php

namespace App\Http\Controllers;

use App\Models\CustomerInstallation;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entregas, devoluciones y kardex del inventario.
 *
 * Todas estas rutas exigen permiso de inventario (ver routes/api.php), y por eso
 * aquí SÍ se puede mover un equipo que tiene otro técnico: recoger lo que Juan
 * no usó es justamente el trabajo de esta pantalla. Lo que no se permite en
 * ningún caso es consumir existencias ajenas sin dejar el traspaso escrito —de
 * eso se encarga InventoryLedger, que es el único que toca saldos.
 */
class InventoryMovementController extends Controller
{
    /**
     * Motivos de «Descontar material»: el tipo con que queda en el kardex y el
     * texto que encabeza la nota. Un error de digitación es AJUSTE y no BAJA:
     * los metros que nunca existieron no son material perdido.
     */
    private const WRITE_OFF_REASONS = [
        'ingreso_de_mas' => ['type' => InventoryMovement::TYPE_AJUSTE, 'label' => 'Corrección: se ingresó de más'],
        'danado'         => ['type' => InventoryMovement::TYPE_BAJA,   'label' => 'Dañado'],
        'perdido'        => ['type' => InventoryMovement::TYPE_BAJA,   'label' => 'Perdido o faltante'],
        'devuelto'       => ['type' => InventoryMovement::TYPE_BAJA,   'label' => 'Devuelto al proveedor'],
    ];

    public function __construct(private InventoryLedger $ledger)
    {
    }

    /** Kardex: el historial, del más reciente al más antiguo. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'device_id'   => 'nullable|integer',
            'stock_id'    => 'nullable|integer',
            'holder_type' => 'nullable|in:branch,user,customer,supplier,scrap',
            'holder_id'   => 'nullable|integer',
            'type'        => 'nullable|in:' . implode(',', InventoryMovement::TYPES),
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
            'per_page'    => 'nullable|integer|min:1|max:200',
        ]);

        $movements = InventoryMovement::with([
                'stock:id,brand,model,unit',
                'device:id,serial,mac',
                'creator:id,user_name,user_lastname,name',
            ])
            ->when($data['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($data['stock_id'] ?? null, fn ($q, $v) => $q->where('stock_id', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            // Un custodio aparece tanto si el movimiento salió de él como si
            // llegó a él: "todo lo de Juan" son sus entradas y sus salidas.
            ->when(($data['holder_type'] ?? null) !== null, function ($q) use ($data) {
                $type = $data['holder_type'];
                $id   = $data['holder_id'] ?? null;

                $q->where(function ($sub) use ($type, $id) {
                    $sub->where(fn ($s) => $s->where('from_type', $type)->where('from_id', $id))
                        ->orWhere(fn ($s) => $s->where('to_type', $type)->where('to_id', $id));
                });
            })
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50);

        $labels = $this->holderLabels($movements->getCollection());

        // «→ Cliente» sin id = la orden era de un prospecto. El kardex es
        // append-only, así que el id no se rellena después: se resuelve al
        // leer, con el cliente de la orden si ya se convirtió o el prospecto si no.
        $prospectByInstallation = $this->prospectLabelsFor($movements->getCollection());
        $partyLabel = function (?string $type, $id, ?int $installationId) use ($labels, $prospectByInstallation) {
            if ($type === InventoryMovement::HOLDER_CUSTOMER && $id === null && $installationId
                && isset($prospectByInstallation[$installationId])) {
                return $prospectByInstallation[$installationId];
            }

            return $this->holderLabel($labels, $type, $id);
        };

        $movements->getCollection()->transform(fn (InventoryMovement $m) => [
            'id'            => $m->id,
            'type'          => $m->type,
            'quantity'      => (float) $m->quantity,
            'device_id'     => $m->device_id,
            'serial'        => $m->device?->serial ?? $m->device_serial,
            'mac'           => $m->device?->mac,
            'stock_id'      => $m->stock_id,
            'item'          => trim(($m->stock?->brand ?? '') . ' ' . ($m->stock?->model ?? '')) ?: 'Equipo',
            'unit'          => $m->stock?->unit,
            'from'          => $partyLabel($m->from_type, $m->from_id, $m->installation_id),
            // Un ajuste también sale hacia «baja» (deja de contarse), pero
            // leer «CHAGUANI → Baja» haría pensar que el material se perdió.
            'to'            => $m->type === InventoryMovement::TYPE_AJUSTE && $m->to_type === InventoryMovement::HOLDER_SCRAP
                ? 'Corrección de inventario'
                : $partyLabel($m->to_type, $m->to_id, $m->installation_id),
            'installation_id' => $m->installation_id,
            'support_ticket_id' => $m->support_ticket_id,
            'notes'         => $m->notes,
            'created_at'    => $m->created_at,
            'created_by'    => $m->creator
                ? (trim(($m->creator->user_name ?? '') . ' ' . ($m->creator->user_lastname ?? '')) ?: $m->creator->name)
                : null,
        ]);

        return response()->json($movements);
    }

    /**
     * Qué tiene encima un custodio: equipos serializados y saldos de material.
     * Es la pantalla "¿qué carga Juan?" y la base de la entrega.
     */
    public function holdings(Request $request)
    {
        $data = $request->validate([
            'holder_type' => 'required|in:branch,user',
            'holder_id'   => 'nullable|integer',
        ]);

        $type = $data['holder_type'];
        $id   = $data['holder_id'] ?? null;

        $devices = InventoryDevice::with(['stock:id,brand,model,price,unit'])
            ->when(
                $type === InventoryMovement::HOLDER_USER,
                fn ($q) => $q->heldByUser((int) $id),
                function ($q) use ($id) {
                    $q->where('status', InventoryDevice::STATUS_STOCK);
                    $id === null ? $q->whereNull('branch_id') : $q->where('branch_id', (int) $id);
                }
            )
            ->orderBy('id')
            ->get()
            ->map(fn (InventoryDevice $d) => [
                'id'     => $d->id,
                'serial' => $d->serial,
                'mac'    => $d->mac,
                'item'   => $d->stock?->label() ?? 'Equipo',
                'price'  => $d->stock?->price,
            ]);

        $materials = $id === null
            ? collect()
            : InventoryBalance::with(['stock:id,brand,model,price,unit,is_serialized,quantity_decimals'])
                ->heldBy($type, (int) $id)
                ->where('quantity', '>', 0)
                ->get()
                ->map(fn (InventoryBalance $b) => [
                    'stock_id' => $b->stock_id,
                    'item'     => $b->stock?->label() ?? 'Material',
                    'unit'     => $b->stock?->unit,
                    'decimals' => $b->stock?->quantityDecimals() ?? 2,
                    'quantity' => (float) $b->quantity,
                ]);

        return response()->json([
            'devices'   => $devices->values(),
            'materials' => $materials->values(),
        ]);
    }

    /**
     * Dónde hay existencias: cuántos equipos y cuántos materiales con saldo
     * tiene cada bodega o persona. Sólo aparecen los custodios con algo.
     *
     * Entregas abría siempre en «Bodega (sin sucursal)», que nunca tiene
     * materiales —un saldo siempre tiene dueño—, así que la sección de
     * materiales no salía y el ISP creyó que la pantalla ya no los manejaba
     * (Chaguaní, 2026-11-26). Con esto la pantalla abre donde hay algo y el
     * desplegable dice qué tiene cada origen.
     *
     * Cuenta exactamente lo que `holdings()` listaría para cada custodio, en dos
     * consultas agrupadas, con el tenant explícito además del scope global (el
     * mismo criterio que InventoryAvailability).
     */
    public function holdersSummary(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $out = [];

        $add = function (string $type, $id, string $field, int $count) use (&$out) {
            $id  = $id === null ? null : (int) $id;
            $key = $type . ':' . ($id ?? '');
            $out[$key] ??= ['type' => $type, 'id' => $id, 'devices' => 0, 'materials' => 0];
            $out[$key][$field] += $count;
        };

        InventoryDevice::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [InventoryDevice::STATUS_STOCK, InventoryDevice::STATUS_ASSIGNED])
            ->selectRaw('status, branch_id, user_id, COUNT(*) AS total')
            ->groupBy('status', 'branch_id', 'user_id')
            ->get()
            ->each(function ($row) use ($add) {
                // Asignado = en manos de una persona; en bodega = de su sucursal
                // (o «sin sucursal»), igual que holdings().
                $row->status === InventoryDevice::STATUS_ASSIGNED
                    ? $add(InventoryMovement::HOLDER_USER, $row->user_id, 'devices', (int) $row->total)
                    : $add(InventoryMovement::HOLDER_BRANCH, $row->branch_id, 'devices', (int) $row->total);
            });

        InventoryBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('quantity', '>', 0)
            ->selectRaw('holder_type, holder_id, COUNT(*) AS total')
            ->groupBy('holder_type', 'holder_id')
            ->get()
            ->each(fn ($row) => $add($row->holder_type, $row->holder_id, 'materials', (int) $row->total));

        // Un asignado sin persona no lo lista holdings() (exige un id): no se
        // anuncia un origen que al elegirlo saldría vacío.
        return response()->json(array_values(array_filter(
            $out,
            fn (array $h) => ! ($h['type'] === InventoryMovement::HOLDER_USER && $h['id'] === null)
        )));
    }

    /**
     * Material que quedó sin custodio: saldos cuyo dueño ya no existe.
     *
     * Borrar una sucursal o un usuario NO borra sus saldos, y es a propósito:
     * desaparecer existencias en silencio sería peor que dejarlas sin dueño.
     * Pero hasta ahora esas filas sólo se veían consultando la tabla a mano, así
     * que en la práctica eran material perdido — contado en ningún lado y sin
     * forma de recuperarlo desde la aplicación.
     *
     * Esta lista es el primer paso para rescatarlo; el segundo es que el
     * traspaso acepte un origen huérfano (ver assertOrigenUtilizable). P-19.
     */
    public function orphanBalances(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $balances = InventoryBalance::with(['stock:id,brand,model,price,unit,is_serialized,quantity_decimals'])
            ->where('quantity', '>', 0)
            ->where(function ($q) use ($tenantId) {
                $q->where(function ($q) use ($tenantId) {
                    $q->where('holder_type', InventoryBalance::HOLDER_BRANCH)
                        ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                            ->from('inventory_branch')
                            ->whereColumn('inventory_branch.id', 'inventory_balances.holder_id')
                            ->where('inventory_branch.tenant_id', $tenantId));
                })->orWhere(function ($q) use ($tenantId) {
                    $q->where('holder_type', InventoryBalance::HOLDER_USER)
                        ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                            ->from('users')
                            ->whereColumn('users.id', 'inventory_balances.holder_id')
                            ->where('users.tenant_id', $tenantId));
                });
            })
            ->orderBy('holder_type')
            ->orderBy('holder_id')
            ->get()
            ->map(fn (InventoryBalance $b) => [
                'stock_id'    => $b->stock_id,
                'item'        => $b->stock?->label() ?? 'Material',
                'unit'        => $b->stock?->unit,
                'quantity'    => (float) $b->quantity,
                'holder_type' => $b->holder_type,
                'holder_id'   => $b->holder_id,
                // Ya no hay nombre que mostrar: el custodio se borró. Se dice
                // qué era, para que el almacenista sepa dónde ir a buscar.
                'holder_label' => $b->holder_type === InventoryBalance::HOLDER_USER
                    ? "Usuario eliminado (#{$b->holder_id})"
                    : "Sucursal eliminada (#{$b->holder_id})",
            ]);

        return response()->json($balances->values());
    }

    /**
     * Entrega/traspaso: mueve equipos y/o material a un custodio.
     * Sin origen en un material, el movimiento es una ENTRADA (compra o ajuste).
     *
     * Todo o nada. Cada línea ya iba en su propia transacción dentro del
     * ledger, pero el ciclo no: si la tercera línea fallaba (decimales, saldo
     * insuficiente, equipo instalado) las dos primeras quedaban grabadas y la
     * pantalla decía «error», así que el usuario reintentaba y las duplicaba.
     * Con «Ingresar al inventario» mandando varias líneas por compra, eso
     * pasaba a ser fácil de provocar.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'to_type'                => 'required|in:branch,user',
            'to_id'                  => 'required|integer',
            'device_ids'             => 'nullable|array',
            'device_ids.*'           => 'integer',
            'materials'              => 'nullable|array',
            'materials.*.stock_id'   => 'required|integer',
            'materials.*.quantity'   => 'required|numeric|min:0.01',
            'materials.*.source_type' => 'nullable|in:branch,user',
            'materials.*.source_id'  => 'nullable|integer',
            'notes'                  => 'nullable|string|max:255',
        ]);

        $this->assertHolderExists($data['to_type'], (int) $data['to_id'], (int) $request->user()->tenant_id);

        if (empty($data['device_ids']) && empty($data['materials'])) {
            throw ValidationException::withMessages([
                'device_ids' => 'Selecciona al menos un equipo o material para entregar.',
            ]);
        }

        $actor = $request->user();

        $moved = DB::transaction(function () use ($data, $actor) {
            $moved = 0;

            foreach ($data['device_ids'] ?? [] as $deviceId) {
                $device = InventoryDevice::with('stock')->findOrFail($deviceId);
                $this->ledger->transferDevice(
                    $device,
                    $data['to_type'],
                    (int) $data['to_id'],
                    $actor,
                    $data['notes'] ?? null
                );
                $moved++;
            }

            foreach ($data['materials'] ?? [] as $material) {
                $stock = InventoryStock::findOrFail($material['stock_id']);

                if ($stock->is_serialized) {
                    throw ValidationException::withMessages([
                        'materials' => "{$stock->label()} se maneja por serial: entrégalo eligiendo sus equipos, no por cantidad.",
                    ]);
                }

                $sourceType = $material['source_type'] ?? null;
                $sourceId   = $material['source_id'] ?? null;

                if ($sourceType !== null) {
                    $this->assertOrigenUtilizable($sourceType, (int) $sourceId, $stock);
                }

                $this->ledger->transferQuantity(
                    $stock,
                    $sourceType,
                    $sourceId === null ? null : (int) $sourceId,
                    $data['to_type'],
                    (int) $data['to_id'],
                    (float) $material['quantity'],
                    $actor,
                    $data['notes'] ?? null
                );
                $moved++;
            }

            return $moved;
        });

        return response()->json([
            'message' => $moved === 1
                ? 'Movimiento registrado.'
                : "{$moved} movimientos registrados.",
        ], 201);
    }

    /** Baja de un equipo (dañado, perdido o devuelto al proveedor). */
    public function retire(Request $request, $deviceId)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:255',
        ]);

        $device = InventoryDevice::with('stock')->findOrFail($deviceId);

        $this->ledger->retireDevice($device, $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => 'Equipo dado de baja.']);
    }

    /**
     * Descontar material de una bodega o persona: corregir una entrada que se
     * digitó de más (ajuste) o dar de baja lo dañado, perdido o devuelto.
     *
     * Nada se borra: el saldo baja y queda la línea en el kardex con el motivo
     * y quien lo hizo. Lo que sí NO hace es tocar el gasto automático de la
     * entrada (KAN-91): no sabe cuál de las entradas fue la equivocada, y
     * adivinarlo sería mover dinero a ciegas. Si la empresa tiene ese gasto
     * activo, la respuesta lo avisa para que se corrija a mano (P-125).
     */
    public function writeOff(Request $request)
    {
        $data = $request->validate([
            'stock_id'    => 'required|integer',
            'holder_type' => 'required|in:branch,user',
            'holder_id'   => 'required|integer',
            'quantity'    => 'required|numeric|min:0.01',
            'reason'      => 'required|in:' . implode(',', array_keys(self::WRITE_OFF_REASONS)),
            'notes'       => 'nullable|string|max:200',
        ], [
            'reason.required' => 'Elige el motivo del descuento.',
            'reason.in'       => 'Elige el motivo del descuento.',
        ]);

        // Con scope de tenant: un producto de otra empresa responde 404.
        $stock  = InventoryStock::findOrFail($data['stock_id']);
        $reason = self::WRITE_OFF_REASONS[$data['reason']];
        $nota   = trim((string) ($data['notes'] ?? ''));

        $movement = $this->ledger->writeOffQuantity(
            $stock,
            $data['holder_type'],
            (int) $data['holder_id'],
            (float) $data['quantity'],
            $reason['type'],
            $request->user(),
            $nota === '' ? $reason['label'] : "{$reason['label']} · {$nota}"
        );

        $warnings = [];
        if ($reason['type'] === InventoryMovement::TYPE_AJUSTE
            && Tenant::find($stock->tenant_id)?->inventory_entry_creates_expense) {
            $warnings[] = 'Tu empresa registra un gasto por cada entrada de inventario, y esta corrección no lo '
                . 'cambia: ajusta ese gasto en Finanzas → Gastos.';
        }

        return response()->json([
            'message'     => $reason['type'] === InventoryMovement::TYPE_AJUSTE
                ? 'Corrección registrada.'
                : 'Baja registrada.',
            'movement_id' => $movement->id,
            'type'        => $movement->type,
            'remaining'   => $this->ledger->balanceOf($stock, $data['holder_type'], (int) $data['holder_id']),
            'warnings'    => $warnings,
        ], 201);
    }

    /**
     * El destino tiene que existir DENTRO del tenant. Sin esta comprobación,
     * un id de otra empresa se guardaría como custodio y las existencias
     * saldrían del inventario sin destino real.
     */
    /**
     * El ORIGEN de un traspaso puede ser un custodio que ya no existe.
     *
     * Es la única forma de rescatar un saldo huérfano: si se exigiera que el
     * custodio siga vivo, el material de una sucursal borrada quedaría atrapado
     * para siempre — visible en la lista de huérfanos y sin poder moverse, que
     * es la mitad inútil del arreglo.
     *
     * No es un agujero: sólo se acepta el origen si existe de verdad una fila de
     * saldo suya con este material. No se puede inventar un origen para sacar
     * existencias de la nada; lo que hay es lo que se puede mover. El destino
     * sigue teniendo que existir (assertHolderExists), porque ahí sí estaríamos
     * mandando material a un custodio inventado.
     */
    private function assertOrigenUtilizable(string $type, int $id, InventoryStock $stock): void
    {
        $tieneSaldo = InventoryBalance::heldBy($type, $id)
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($tieneSaldo) {
            return;
        }

        // Si además el custodio no existe, el problema es el ORIGEN, no el
        // destino: delegar en assertHolderExists() daría el mensaje equivocado
        // ("el destino no existe") y mandaría al usuario a revisar el campo que
        // sí estaba bien.
        $existe = $type === InventoryMovement::HOLDER_USER
            ? User::where('id', $id)->where('tenant_id', $stock->tenant_id)->exists()
            : InventoryBranch::where('id', $id)->exists();

        if (! $existe) {
            throw ValidationException::withMessages([
                'materials' => "El origen seleccionado ya no existe y no tiene saldo de {$stock->label()}: "
                    . 'no hay de dónde sacar ese material.',
            ]);
        }

        // El custodio existe pero no tiene saldo de este material. Que lo diga
        // InventoryLedger, que es quien conoce la cantidad exacta disponible.
    }

    private function assertHolderExists(string $type, int $id, int $tenantId): void
    {
        // `User` no tiene scope de tenant (InventoryBranch sí): sin el where
        // explícito, el id de una persona de otra empresa pasaba como destino.
        $exists = $type === InventoryMovement::HOLDER_USER
            ? User::where('id', $id)->where('tenant_id', $tenantId)->exists()
            : InventoryBranch::where('id', $id)->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'to_id' => 'El destino seleccionado no existe en esta empresa.',
            ]);
        }
    }

    /**
     * Nombres de los custodios que aparecen EN ESTA PÁGINA del kardex, en dos
     * consultas. Cargar todos los usuarios del tenant sería traer también los
     * miles de clientes para pintar cincuenta filas.
     */
    private function holderLabels($movements): array
    {
        $ids = ['branch' => [], 'user' => []];

        foreach ($movements as $movement) {
            foreach ([[$movement->from_type, $movement->from_id], [$movement->to_type, $movement->to_id]] as [$type, $id]) {
                if ($id === null) {
                    continue;
                }
                if ($type === InventoryMovement::HOLDER_BRANCH) {
                    $ids['branch'][$id] = true;
                } elseif (in_array($type, [InventoryMovement::HOLDER_USER, InventoryMovement::HOLDER_CUSTOMER], true)) {
                    $ids['user'][$id] = true;
                }
            }
        }

        $branches = empty($ids['branch'])
            ? []
            : InventoryBranch::whereIn('id', array_keys($ids['branch']))->pluck('name', 'id')->toArray();

        $users = empty($ids['user'])
            ? []
            : User::whereIn('id', array_keys($ids['user']))
                ->get(['id', 'user_name', 'user_lastname', 'name'])
                ->mapWithKeys(fn ($u) => [
                    $u->id => trim(($u->user_name ?? '') . ' ' . ($u->user_lastname ?? '')) ?: $u->name,
                ])
                ->toArray();

        return ['branch' => $branches, 'user' => $users, 'customer' => $users];
    }

    /**
     * Quién es el «cliente» anónimo de las órdenes de prospecto que aparecen en
     * esta página del kardex: «Nombre del cliente» si el prospecto ya se
     * convirtió, o «Prospecto: Nombre (orden #N)» si todavía no. Dos consultas.
     *
     * @return array<int, string> installation_id => etiqueta
     */
    private function prospectLabelsFor($movements): array
    {
        $ids = $movements
            ->filter(fn (InventoryMovement $m) => $m->installation_id && (
                ($m->to_type === InventoryMovement::HOLDER_CUSTOMER && $m->to_id === null)
                || ($m->from_type === InventoryMovement::HOLDER_CUSTOMER && $m->from_id === null)
            ))
            ->pluck('installation_id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return CustomerInstallation::with([
                'prospect:id,name,last_name',
                'customer:id,user_name,user_lastname,name',
            ])
            ->whereIn('id', $ids)
            ->get(['id', 'customer_id', 'prospect_id'])
            ->mapWithKeys(function ($i) {
                if ($i->customer) {
                    $u = $i->customer;

                    return [$i->id => trim(($u->user_name ?? '') . ' ' . ($u->user_lastname ?? '')) ?: $u->name];
                }

                if ($i->prospect) {
                    $p = $i->prospect;

                    return [$i->id => 'Prospecto: '
                        . (trim(($p->name ?? '') . ' ' . ($p->last_name ?? '')) ?: "#{$p->id}")
                        . " (orden #{$i->id})"];
                }

                return [];
            })
            ->toArray();
    }

    private function holderLabel(array $labels, ?string $type, ?int $id): string
    {
        return match ($type) {
            InventoryMovement::HOLDER_SUPPLIER => 'Proveedor / compra',
            InventoryMovement::HOLDER_SCRAP    => 'Baja',
            InventoryMovement::HOLDER_BRANCH   => $labels['branch'][$id] ?? 'Bodega',
            InventoryMovement::HOLDER_USER     => $labels['user'][$id] ?? 'Usuario',
            InventoryMovement::HOLDER_CUSTOMER => $labels['customer'][$id] ?? 'Cliente',
            default                            => '—',
        };
    }
}
