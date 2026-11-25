<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Support\TicketCatalogs;
use App\Support\TicketWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El cliente visto desde soporte (bitácora § 133).
 *
 * DOS PREGUNTAS QUE EL TICKET NO SABÍA RESPONDER
 *
 *   · Al ABRIR: ¿qué cliente es? El alta buscaba sólo por nombre en
 *     `/customers`, que además exige `view_clients` —quien recibe llamadas no
 *     siempre lo tiene— y no decía si el cliente ya tenía un ticket abierto.
 *   · Al ATENDER: ¿dónde es, qué tiene y cómo está? La ficha del ticket sólo
 *     traía nombre, correo y teléfono.
 *
 * QUÉ SE ENSEÑA Y A QUIÉN
 *
 * Lo técnico (dirección, plan, router, NAP, IP, estado del servicio) va con el
 * permiso del ticket: es lo que hace falta para atenderlo. El DINERO (saldo,
 * facturas vencidas) sólo a quien ya puede ver facturación: un técnico no
 * necesita saber cuánto debe el cliente para cambiarle un conector.
 *
 * Nunca credenciales: ni contraseñas PPPoE/HotSpot ni usuario PPPoE.
 */
class SupportCustomerController extends Controller
{
    public function __construct(private readonly TicketCatalogs $catalogs)
    {
    }

    /**
     * Clientes para el buscador del alta: una fila liviana por cliente con lo
     * que permite reconocerlo (cédula, teléfono, dirección, IP, plan) y cuántos
     * tickets abiertos tiene.
     */
    public function options(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        if (!$tenantId) {
            return response()->json(['data' => []]);
        }

        $filas = CustomerProfile::query()
            ->join('users', 'customer_profile.user_id', '=', 'users.id')
            ->leftJoin('service_plan', 'customer_profile.service_id', '=', 'service_plan.id')
            ->where('users.tenant_id', $tenantId)
            ->orderBy('customer_profile.name')
            ->orderBy('customer_profile.last_name')
            ->get([
                'customer_profile.user_id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.cedula',
                'customer_profile.address',
                'customer_profile.city',
                'customer_profile.ip_user',
                'customer_profile.service_status',
                'users.tel',
                'service_plan.name as plan_name',
            ]);

        $abiertos = $this->ticketsAbiertosPorCliente($tenantId);

        return response()->json([
            'data' => $filas->map(fn ($f) => [
                'user_id'        => (int) $f->user_id,
                'name'           => $f->name,
                'last_name'      => $f->last_name,
                'cedula'         => $f->cedula,
                'tel'            => $f->tel,
                'address'        => $f->address,
                'city'           => $f->city,
                'ip_user'        => $f->ip_user,
                'plan_name'      => $f->plan_name,
                'service_status' => $f->service_status,
                'open_tickets'   => (int) ($abiertos[(int) $f->user_id] ?? 0),
            ])->values(),
        ]);
    }

    /**
     * Ficha de un cliente para el ticket: datos técnicos, tickets abiertos y,
     * a quien puede ver facturación, el saldo.
     *
     * `?exclude=<ticket>` deja fuera el ticket desde el que se mira, para que
     * «otros tickets abiertos» no se cuente a sí mismo.
     */
    public function context(Request $request, $userId): JsonResponse
    {
        $usuario  = $request->user();
        $tenantId = $usuario?->tenant_id;

        $perfil = CustomerProfile::query()
            ->join('users', 'customer_profile.user_id', '=', 'users.id')
            ->leftJoin('service_plan', 'customer_profile.service_id', '=', 'service_plan.id')
            ->leftJoin('router', 'customer_profile.router_id', '=', 'router.id')
            ->leftJoin('sectorial as sector', 'customer_profile.sectorial_id', '=', 'sector.id')
            ->leftJoin('sectorial as olt', 'customer_profile.olt_id', '=', 'olt.id')
            ->where('customer_profile.user_id', (int) $userId)
            // 404 y no 403 para el cliente de otro ISP: quien no puede verlo
            // tampoco debe poder deducir que existe.
            ->where('users.tenant_id', $tenantId)
            ->first([
                'customer_profile.user_id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.cedula',
                'customer_profile.address',
                'customer_profile.city',
                'customer_profile.latitude',
                'customer_profile.longitude',
                'customer_profile.ip_user',
                'customer_profile.service_status',
                'customer_profile.is_fiber',
                'customer_profile.nap_port',
                'customer_profile.installation_date',
                'customer_profile.credit_balance',
                'users.email',
                'users.tel',
                'service_plan.name as plan_name',
                'router.name as router_name',
                'sector.name as sectorial_name',
                'sector.element_type as sectorial_type',
                'olt.name as olt_name',
            ]);

        if (!$perfil) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $modo = TicketWorkflow::modoDelTenant($tenantId);
        $cerrados = $this->catalogs->ids(TicketCatalogs::STATUS, TicketWorkflow::codigosDelGrupo(TicketWorkflow::GRUPO_CERRADO));

        $abiertos = SupportTicket::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $userId)
            ->whereNotIn('status_id', $cerrados)
            ->when($request->filled('exclude'), fn ($q) => $q->where('id', '!=', (int) $request->query('exclude')))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'subject', 'status_id', 'created_at', 'staff_id'])
            ->map(fn (SupportTicket $t) => [
                'id'           => $t->id,
                'subject'      => $t->subject,
                'status'       => $t->status,
                'status_label' => (TicketWorkflow::esSimple($modo) ? TicketWorkflow::etiquetaSimple($t->status) : null)
                    ?? $t->status_label,
                'status_group' => TicketWorkflow::grupoDe($t->status),
                'created_at'   => $t->created_at?->toJSON(),
            ])
            ->values();

        $puede = fn (string $p): bool => (int) $usuario->role_id === 1 || $usuario->hasPermission($p);

        $facturacion = null;

        if ($puede(Permissions::VIEW_BILLING) || $puede(Permissions::VIEW_INVOICES)) {
            $pendientes = Invoice::query()
                ->where('customer_id', (int) $userId)
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
                ->get(['id', 'status', 'balance_due', 'total']);

            $facturacion = [
                'pending_invoices' => $pendientes->count(),
                'overdue_invoices' => $pendientes->where('status', Invoice::STATUS_OVERDUE)->count(),
                'balance_due'      => round((float) $pendientes->sum(fn ($f) => (float) ($f->balance_due ?? $f->total)), 2),
                'credit_balance'   => round((float) ($perfil->credit_balance ?? 0), 2),
            ];
        }

        return response()->json([
            'customer' => [
                'user_id'           => (int) $perfil->user_id,
                'name'              => $perfil->name,
                'last_name'         => $perfil->last_name,
                'cedula'            => $perfil->cedula,
                'email'             => $perfil->email,
                'tel'               => $perfil->tel,
                'address'           => $perfil->address,
                'city'              => $perfil->city,
                'latitude'          => $perfil->latitude,
                'longitude'         => $perfil->longitude,
                'ip_user'           => $perfil->ip_user,
                'service_status'    => $perfil->service_status,
                'is_fiber'          => (bool) $perfil->is_fiber,
                'plan_name'         => $perfil->plan_name,
                'router_name'       => $perfil->router_name,
                'sectorial_name'    => $perfil->sectorial_name,
                'sectorial_type'    => $perfil->sectorial_type,
                'olt_name'          => $perfil->olt_name,
                'nap_port'          => $perfil->nap_port,
                'installation_date' => $perfil->installation_date,
            ],
            'open_tickets' => $abiertos,
            // null = quien mira no puede ver facturación (no «sin deuda»).
            'billing'      => $facturacion,
        ]);
    }

    /** @return array<int, int> user_id => tickets abiertos */
    private function ticketsAbiertosPorCliente(int $tenantId): array
    {
        $cerrados = $this->catalogs->ids(TicketCatalogs::STATUS, TicketWorkflow::codigosDelGrupo(TicketWorkflow::GRUPO_CERRADO));

        return SupportTicket::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('user_id')
            ->whereNotIn('status_id', $cerrados)
            ->toBase()
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
