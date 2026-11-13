<?php

namespace App\Http\Controllers;

use App\Models\CustomerInstallation;
use App\Models\InstallationEquipment;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryAvailability;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Equipos y materiales descargados en una orden de instalación.
 *
 * Antes esto era un único campo dentro del JSON de la hoja, así que una visita
 * sólo podía declarar un aparato y los consumibles se escribían a mano. Ahora
 * cada línea descuenta existencias de verdad y deja su rastro en el kardex, y
 * por eso todas las escrituras pasan por InventoryLedger.
 */
class InstallationEquipmentController extends Controller
{
    public function __construct(
        private InventoryLedger $ledger,
        private InventoryAvailability $availability,
    ) {
    }

    private function resolveInstallation(Request $request, $installationId): CustomerInstallation
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        return CustomerInstallation::where('tenant_id', $tenantId)->findOrFail($installationId);
    }

    /**
     * Una orden firmada no admite cambios en lo que el cliente firmó.
     *
     * Lo que sí admite es REGISTRAR LO QUE FALTÓ: el técnico hizo firmar sin
     * haber cargado los amarres o el router, y el equipo ya está en la casa del
     * cliente mientras el inventario lo sigue dando en bodega. Eso lo hace sólo
     * quien administra inventario y con un motivo, y la línea queda marcada
     * `after_signature`: no figura en el acta firmada y la pantalla lo dice.
     *
     * Devoluciones y correcciones de lo firmado siguen siendo la conciliación
     * de la entrega B; esto no las sustituye.
     *
     * @return string|null el motivo del registro tardío, o null si la orden no está firmada
     */
    private function lateReasonFor(Request $request, CustomerInstallation $installation): ?string
    {
        if (!$installation->isSigned()) {
            return null;
        }

        if (!$this->ledger->managesInventory($request->user())) {
            throw ValidationException::withMessages([
                'installation' => 'La orden ya está firmada: sus equipos y materiales no se modifican desde aquí. '
                    . 'Si faltó registrar algo, pídeselo a quien administra el inventario.',
            ]);
        }

        $reason = trim((string) $request->input('late_reason', ''));

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'installation' => 'La orden ya está firmada. Para registrar lo que faltó, escribe el motivo '
                    . '(p. ej. «el técnico no alcanzó a cargar los amarres antes de la firma»).',
            ]);
        }

        return mb_substr($reason, 0, 255);
    }

    /** Líneas ya cargadas en la orden. */
    public function index(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);

        return response()->json($this->rows($installation));
    }

    /**
     * Catálogo de lo que este usuario puede descargar en esta orden concreta:
     * lo suyo, lo del técnico asignado y —si administra inventario— las bodegas.
     */
    public function available(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);
        $actor        = $request->user();

        $sources = $this->allowedSources($actor, $installation);

        // El filtro por custodio va en SQL y no en PHP: un ISP con miles de
        // equipos no debe traerlos todos a memoria para descartar la mayoría.
        $devices = InventoryDevice::with(['stock:id,brand,model,price,is_serialized,unit'])
            ->available()
            // Un modelo «por cantidad» no se entrega por unidad aunque tenga filas
            // con serial heredadas del formulario de equipos (ver InventoryDeviceController).
            ->where(fn ($q) => $q->whereNull('stock_id')->orWhereHas('stock', fn ($s) => $s->where('is_serialized', true)))
            ->where(function ($query) use ($sources) {
                foreach ($sources as $source) {
                    if ($source['type'] === InventoryMovement::HOLDER_USER) {
                        $query->orWhere(fn ($q) => $q
                            ->where('status', InventoryDevice::STATUS_ASSIGNED)
                            ->where('user_id', $source['id']));
                        continue;
                    }

                    $query->orWhere(function ($q) use ($source) {
                        $q->where('status', InventoryDevice::STATUS_STOCK);
                        $source['id'] === null
                            ? $q->whereNull('branch_id')
                            : $q->where('branch_id', $source['id']);
                    });
                }
            })
            ->orderBy('id')
            ->get()
            ->map(function (InventoryDevice $device) use ($sources) {
                $holder = $this->ledger->currentHolderOf($device);

                return [
                    'id'           => $device->id,
                    'serial'       => $device->serial,
                    'mac'          => $device->mac,
                    'stock_id'     => $device->stock_id,
                    'brand'        => $device->stock?->brand,
                    'model'        => $device->stock?->model,
                    'price'        => $device->stock?->price,
                    'source_type'  => $holder['type'],
                    'source_id'    => $holder['id'],
                    'source_label' => $this->sourceLabel($sources, $holder['type'], $holder['id']),
                ];
            })
            ->values();

        // Consumibles: un renglón por modelo y custodio con saldo. Los saldos
        // siempre tienen custodio, así que la "bodega sin sucursal" no aplica.
        $holders = array_values(array_filter(
            $sources,
            fn ($source) => $source['id'] !== null
        ));

        $balances = InventoryBalance::with(['stock:id,brand,model,price,is_serialized,unit,quantity_decimals'])
            ->where('quantity', '>', 0)
            ->where(function ($query) use ($holders) {
                if (empty($holders)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                foreach ($holders as $holder) {
                    $query->orWhere(fn ($q) => $q
                        ->where('holder_type', $holder['type'])
                        ->where('holder_id', $holder['id']));
                }
            })
            ->get()
            ->map(fn (InventoryBalance $b) => [
                'stock_id'     => $b->stock_id,
                'brand'        => $b->stock?->brand,
                'model'        => $b->stock?->model,
                'unit'         => $b->stock?->unit,
                'decimals'     => $b->stock?->quantityDecimals() ?? 2,
                'price'        => $b->stock?->price,
                'quantity'     => (float) $b->quantity,
                'source_type'  => $b->holder_type,
                'source_id'    => $b->holder_id,
                'source_label' => $this->sourceLabel($sources, $b->holder_type, $b->holder_id),
            ])
            ->values();

        return response()->json([
            'sources'   => $sources,
            'devices'   => $devices,
            'materials' => $balances,
            // Por qué `materials` puede venir vacío. La sección de consumibles
            // se muestra SIEMPRE y usa esto para explicarlo, en vez de
            // desaparecer y dejar a quien la busca sin saber qué falta.
            'materials_status' => $this->availability->materialsStatus(
                (int) $installation->tenant_id,
                $balances->count(),
                'esta orden'
            ),
            'locked' => $this->lockState($installation, $actor),
        ]);
    }

    /**
     * Si la orden admite cambios en sus líneas, y por qué no. La pantalla lo
     * usa para esconder los controles; quien decide es store()/destroy().
     *
     * `late_allowed`: la orden está firmada y este usuario puede registrar lo
     * que faltó (con motivo). Lo firmado sigue sin tocarse.
     *
     * @return array{is_locked: bool, reason: string|null, message: string|null, late_allowed: bool}
     */
    private function lockState(CustomerInstallation $installation, User $actor): array
    {
        if ($installation->status === 'cancelada') {
            return [
                'is_locked'    => true,
                'reason'       => 'cancelled',
                'message'      => 'La orden está cancelada: no se le cargan equipos ni materiales.',
                'late_allowed' => false,
            ];
        }

        if ($installation->isSigned()) {
            $late = $this->ledger->managesInventory($actor);

            return [
                'is_locked'    => true,
                'reason'       => 'signed',
                'message'      => $late
                    ? 'La orden ya está firmada: lo que el cliente firmó no cambia. Si faltó registrar algún equipo o '
                        . 'material, regístralo abajo con el motivo; quedará marcado como registrado después de la firma.'
                    : 'La orden ya está firmada: sus equipos y materiales quedan como se firmaron. Si faltó registrar '
                        . 'algo, pídeselo a quien administra el inventario.',
                'late_allowed' => $late,
            ];
        }

        return ['is_locked' => false, 'reason' => null, 'message' => null, 'late_allowed' => false];
    }

    /**
     * Descarga un equipo (device_id) o un material (stock_id + quantity).
     */
    public function store(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);
        $actor        = $request->user();

        if ($installation->status === 'cancelada') {
            throw ValidationException::withMessages([
                'installation' => 'La orden está cancelada: no se le cargan equipos ni materiales.',
            ]);
        }

        $lateReason = $this->lateReasonFor($request, $installation);

        $data = $request->validate([
            'device_id'   => 'nullable|integer',
            'stock_id'    => 'nullable|integer',
            'quantity'    => 'nullable|numeric|min:0.01',
            'source_type' => 'nullable|in:branch,user',
            'source_id'   => 'nullable|integer',
            'notes'       => 'nullable|string|max:255',
            // Clave del intento que genera la pantalla: el mismo «Agregar»
            // reenviado (doble clic, señal que se cae) no descuenta dos veces.
            'client_request_id' => 'nullable|string|max:64',
            'late_reason'       => 'nullable|string|max:255',
        ]);

        if (empty($data['device_id']) && empty($data['stock_id'])) {
            throw ValidationException::withMessages([
                'device_id' => 'Indica el equipo del inventario o el material que se usó.',
            ]);
        }

        $requestId = $data['client_request_id'] ?? null;

        [$item, $created] = $this->ledger->once(
            InstallationEquipment::class,
            (int) $installation->tenant_id,
            'installation_id',
            (int) $installation->id,
            $requestId,
            fn () => $this->write($installation, $actor, $data, $requestId, $lateReason)
        );

        return response()->json([
            'message'   => $created
                ? ($item->device_id
                    ? 'Equipo cargado a la instalación y descontado del inventario.'
                    : 'Material registrado en la instalación y descontado del inventario.')
                : 'Esta línea ya estaba registrada: no se descontó otra vez.',
            'item'      => $this->row($item->fresh(['stock', 'device.stock'])),
            'equipment' => $this->rows($installation),
            'replayed'  => !$created,
        ], $created ? 201 : 200);
    }

    /**
     * Escribe la línea: un equipo por serial o un material por cantidad.
     *
     * Con `$lateReason` la línea se registra después de la firma: el motivo va
     * en la línea y, delante de la nota, en el kardex, para que el movimiento
     * explique por sí solo por qué entró tarde.
     */
    private function write(
        CustomerInstallation $installation,
        User $actor,
        array $data,
        ?string $requestId,
        ?string $lateReason = null
    ): InstallationEquipment {
        $notes = $data['notes'] ?? null;

        if ($lateReason !== null) {
            $notes = 'Registrado después de la firma: ' . $lateReason . ($notes ? " · {$notes}" : '');
        }

        // Misma transacción: nunca queda una línea tardía sin su marca.
        return DB::transaction(function () use ($installation, $actor, $data, $requestId, $notes, $lateReason) {
            $item = $this->writeLine($installation, $actor, $data, $requestId, $notes);

            if ($lateReason !== null) {
                $item->forceFill(['after_signature' => true, 'late_reason' => $lateReason])->save();
            }

            return $item;
        });
    }

    private function writeLine(
        CustomerInstallation $installation,
        User $actor,
        array $data,
        ?string $requestId,
        ?string $notes
    ): InstallationEquipment {
        if (!empty($data['device_id'])) {
            $device = InventoryDevice::with('stock')->findOrFail($data['device_id']);

            return $this->ledger->assignDeviceToInstallation(
                $installation,
                $device,
                $actor,
                $notes,
                $requestId
            );
        }

        $stock = InventoryStock::findOrFail($data['stock_id']);

        if ($stock->is_serialized) {
            throw ValidationException::withMessages([
                'stock_id' => "{$stock->label()} se maneja por serial: elígelo de la lista de equipos, no como material.",
            ]);
        }

        if (empty($data['source_type']) || empty($data['source_id'])) {
            throw ValidationException::withMessages([
                'source_type' => 'Indica de qué bodega o de quién sale el material.',
            ]);
        }

        return $this->ledger->assignMaterialToInstallation(
            $installation,
            $stock,
            (float) ($data['quantity'] ?? 1),
            $data['source_type'],
            (int) $data['source_id'],
            $actor,
            $notes,
            $requestId
        );
    }

    /**
     * Quita una línea capturada por error y devuelve la existencia a quien la
     * aportó.
     *
     * OJO con lo que esto NO es: no es la devolución de un material gastado.
     * Deshace la captura como si nunca se hubiera usado, así que sólo tiene
     * sentido antes de la firma y para un error de digitación («cargué la LDF
     * equivocada», «puse 40 m en vez de 4»). Un consumo real que haya que
     * revertir, total o parcialmente, necesita la conciliación auditada de la
     * entrega B. Por eso la firma lo bloquea y la pantalla lo presenta como
     * «Quitar (error de captura)».
     *
     * Con la orden firmada sólo se puede quitar una línea registrada DESPUÉS
     * de la firma, y sólo quien administra inventario: es el mismo error de
     * captura, cometido al registrar lo que faltó. Lo que el cliente firmó no
     * se quita.
     */
    public function destroy(Request $request, $installationId, $itemId)
    {
        $installation = $this->resolveInstallation($request, $installationId);

        $item = InstallationEquipment::where('installation_id', $installation->id)->findOrFail($itemId);

        if ($installation->isSigned()
            && !($item->after_signature && $this->ledger->managesInventory($request->user()))) {
            throw ValidationException::withMessages([
                'installation' => 'La orden ya está firmada: sus equipos y materiales no se modifican desde aquí. '
                    . 'Lo registrado es lo que el cliente firmó.',
            ]);
        }

        $this->ledger->releaseFromInstallation($item, $request->user());

        return response()->json([
            'message'   => 'Línea quitada: la captura se deshizo y la existencia volvió a quien la aportó.',
            'equipment' => $this->rows($installation),
        ]);
    }

    /** Custodios de los que este usuario puede tomar en esta orden. */
    private function allowedSources(User $actor, CustomerInstallation $installation): array
    {
        $sources = [[
            'type'  => InventoryMovement::HOLDER_USER,
            'id'    => (int) $actor->id,
            'label' => 'Mis equipos',
        ]];

        $techId = $installation->technician_id;

        if ($techId && (int) $techId !== (int) $actor->id) {
            $tech = User::find($techId);
            $sources[] = [
                'type'  => InventoryMovement::HOLDER_USER,
                'id'    => (int) $techId,
                'label' => 'Técnico ' . (trim(($tech?->user_name ?? '') . ' ' . ($tech?->user_lastname ?? '')) ?: ($tech?->name ?? 'asignado')),
            ];
        }

        if ($this->ledger->canTakeFrom($actor, InventoryMovement::HOLDER_BRANCH, null, $installation)) {
            foreach (InventoryBranch::orderBy('name')->get() as $branch) {
                $sources[] = [
                    'type'  => InventoryMovement::HOLDER_BRANCH,
                    'id'    => (int) $branch->id,
                    'label' => $branch->name ?: "Sucursal #{$branch->id}",
                ];
            }

            // Equipos cargados sin sucursal: siguen siendo tomables desde bodega.
            $sources[] = [
                'type'  => InventoryMovement::HOLDER_BRANCH,
                'id'    => null,
                'label' => 'Bodega (sin sucursal)',
            ];
        }

        return $sources;
    }

    private function sourceLabel(array $sources, ?string $type, ?int $id): string
    {
        foreach ($sources as $source) {
            if ($source['type'] === $type && (int) $source['id'] === (int) $id) {
                return $source['label'];
            }
        }

        return 'Inventario';
    }

    private function rows(CustomerInstallation $installation)
    {
        return InstallationEquipment::with(['stock', 'device.stock'])
            ->where('installation_id', $installation->id)
            ->orderBy('id')
            ->get()
            ->map(fn (InstallationEquipment $item) => $this->row($item));
    }

    private function row(InstallationEquipment $item): array
    {
        $stock = $item->stock ?? $item->device?->stock;

        return [
            'id'          => $item->id,
            'device_id'   => $item->device_id,
            'stock_id'    => $item->stock_id,
            'label'       => $item->label(),
            'brand'       => $stock?->brand,
            'model'       => $stock?->model,
            'serial'      => $item->device?->serial,
            'mac'         => $item->device?->mac,
            'unit'        => $stock?->unit,
            'decimals'    => $stock?->quantityDecimals() ?? 2,
            'quantity'    => (float) $item->quantity,
            'unit_price'  => $item->unit_price !== null ? (float) $item->unit_price : null,
            'is_device'   => $item->device_id !== null,
            'source_type' => $item->source_type,
            'source_id'   => $item->source_id,
            'notes'       => $item->notes,
            'after_signature' => (bool) $item->after_signature,
            'late_reason'     => $item->late_reason,
            'registered_at'   => $item->created_at?->toIso8601String(),
        ];
    }
}
