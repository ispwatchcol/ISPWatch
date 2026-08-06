<?php

namespace App\Http\Controllers;

use App\Models\AdditionalService;
use App\Models\CustomerAdditionalService;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\Request;

/**
 * Servicios adicionales asignados a un cliente.
 *
 * El cobro no ocurre aquí: estas filas son lo que la generación mensual lee
 * para sumar los adicionales dentro de la factura del cliente. Asignar no
 * factura nada por sí solo.
 *
 * Aislamiento entre empresas: `User` y `CustomerProfile` NO llevan el trait
 * BelongsToTenant, así que el tenant del cliente se comprueba a mano contra el
 * del usuario autenticado. El servicio del catálogo sí está bajo el scope
 * global, y por eso un id ajeno simplemente "no existe".
 */
class CustomerAdditionalServiceController extends Controller
{
    public function __construct(protected BillingService $billingService)
    {
    }

    public function index(Request $request, int $customer)
    {
        if (!$this->customerOfTenant($request, $customer)) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $assignments = CustomerAdditionalService::where('customer_id', $customer)
            ->with(['service:id,name,description,price,proration_mode,charge_on_courtesy_month,is_active', 'assigner:id,name'])
            ->orderByDesc('is_active')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        // effective_price no está en $appends para no disparar una consulta por
        // fila cuando `service` no viene cargado; aquí ya vino, así que se añade.
        $assignments->each->append('effective_price');

        // Marca las que debían cobrarse este mes y no están en ninguna factura.
        // Se calcula con el MISMO filtro que usa el cobro, así que no puede
        // señalar como pendiente algo que el cobro no iba a cobrar igualmente.
        $pendientes = $this->billingService->pendingAdditionalServiceIds(
            (int) $request->user()->tenant_id,
            $customer,
        );

        $assignments->each(fn ($a) => $a->setAttribute('pending_billing', in_array($a->id, $pendientes, true)));

        return response()->json($assignments);
    }

    public function store(Request $request, int $customer)
    {
        if (!$this->customerOfTenant($request, $customer)) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $data = $request->validate([
            'additional_service_id' => 'required|integer',
            'price'                 => 'nullable|numeric|min:0',
            'quantity'              => 'integer|min:1',
            'starts_at'             => 'required|date',
            'ends_at'               => 'nullable|date|after_or_equal:starts_at',
            'notes'                 => 'nullable|string',
        ]);

        // El scope global del catálogo deja fuera los de otros tenants: un id
        // ajeno cae aquí como "no existe", sin filtrar si existe o no.
        $service = AdditionalService::find($data['additional_service_id']);

        if (!$service) {
            return response()->json(['message' => 'El servicio adicional no existe.'], 422);
        }

        if (!$service->is_active) {
            return response()->json([
                'message' => 'Ese servicio está desactivado. Actívalo en el catálogo antes de asignarlo.',
            ], 422);
        }

        // Dos asignaciones activas del mismo servicio al mismo cliente cobrarían
        // dos veces sin que se note en pantalla. Para "dos routers extra" está
        // la cantidad, que es explícita y se ve en la factura.
        $yaAsignado = CustomerAdditionalService::where('customer_id', $customer)
            ->where('additional_service_id', $service->id)
            ->where('is_active', true)
            ->exists();

        if ($yaAsignado) {
            return response()->json([
                'message' => 'El cliente ya tiene este servicio activo. Si necesitas cobrarlo más de una vez, '
                    . 'aumenta la cantidad en la asignación existente.',
            ], 422);
        }

        $assignment = CustomerAdditionalService::create($data + [
            'customer_id' => $customer,
            'assigned_at' => now(),
            'assigned_by' => $request->user()->id,
        ]);

        return response()->json($this->loaded($assignment), 201);
    }

    public function update(Request $request, int $customer, int $id)
    {
        $assignment = $this->findAssignment($request, $customer, $id);

        if (!$assignment) {
            return response()->json(['message' => 'Asignación no encontrada.'], 404);
        }

        $data = $request->validate([
            'price'     => 'nullable|numeric|min:0',
            'quantity'  => 'integer|min:1',
            'starts_at' => 'sometimes|required|date',
            'ends_at'   => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'boolean',
            'notes'     => 'nullable|string',
        ]);

        // Reactivar cuenta como una nueva alta: assigned_at vuelve a decir la
        // verdad sobre desde cuándo el cliente tiene el servicio esta vez.
        if (($data['is_active'] ?? null) === true && !$assignment->is_active) {
            $data['assigned_at'] = now();
            $data['assigned_by'] = $request->user()->id;
        }

        // El servicio del catálogo NO se cambia: sería otra asignación distinta
        // con el historial de cobro de la anterior colgando de ella.
        $assignment->update($data);

        return response()->json($this->loaded($assignment->fresh()));
    }

    public function destroy(Request $request, int $customer, int $id)
    {
        $assignment = $this->findAssignment($request, $customer, $id);

        if (!$assignment) {
            return response()->json(['message' => 'Asignación no encontrada.'], 404);
        }

        // Si ya se cobró en alguna factura, borrarla dejaría esos ítems sin
        // explicación. Una asignación que nunca facturó (alta por error) sí se
        // puede borrar: no hay historial que proteger.
        $cobrada = $assignment->invoiceItems()->count();

        if ($cobrada > 0) {
            return response()->json([
                'message' => "Este servicio ya se cobró en {$cobrada} factura(s). Desactívalo en lugar de "
                    . 'eliminarlo para conservar el historial.',
            ], 422);
        }

        $assignment->delete();

        return response()->json(['message' => 'Asignación eliminada.']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function customerOfTenant(Request $request, int $customerId): ?User
    {
        $tenantId = $request->user()?->tenant_id;

        if (!$tenantId) {
            return null;
        }

        return User::where('id', $customerId)->where('tenant_id', $tenantId)->first();
    }

    /**
     * La asignación tiene que ser DE ese cliente, y el cliente del tenant de
     * quien pregunta. Sin lo primero, un id de asignación válido serviría para
     * editar la de cualquier otro cliente de la misma empresa.
     */
    private function findAssignment(Request $request, int $customerId, int $id): ?CustomerAdditionalService
    {
        if (!$this->customerOfTenant($request, $customerId)) {
            return null;
        }

        return CustomerAdditionalService::where('id', $id)
            ->where('customer_id', $customerId)
            ->first();
    }

    private function loaded(CustomerAdditionalService $assignment): CustomerAdditionalService
    {
        $assignment->load([
            'service:id,name,description,price,proration_mode,charge_on_courtesy_month,is_active',
            'assigner:id,name',
        ]);

        return $assignment->append('effective_price');
    }
}
