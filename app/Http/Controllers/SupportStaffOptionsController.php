<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal del ISP para los desplegables de soporte: «Asignado a», el filtro
 * por técnico y el técnico/acompañante de una intervención.
 *
 * POR QUÉ NO SE USA `/staff`
 * --------------------------
 * `/staff` es la administración de personal y exige `view_staff`, que un
 * técnico no tiene. Las cuatro pantallas de soporte la pedían con el 403
 * silenciado, así que a un técnico le salía el desplegable VACÍO, sin
 * ningún aviso. Y como el técnico es obligatorio en una intervención, tampoco
 * podía registrar qué se hizo en la visita (caso reportado el 2026-11-20). Además
 * `/staff` no excluye a los abonados: a un administrador le ofrecía cientos de
 * clientes como «técnico».
 *
 * QUIÉN ES «TÉCNICO»
 * ------------------
 * Lo decide una CAPACIDAD, no el nombre del rol: `can_intervene` = puede
 * registrar intervenciones (`ticket_intervene`). Antes las pantallas filtraban por
 * nombre de rol exacto «Técnico»/«Tecnico» (P-56), y un ISP cuyo rol se llamara
 * «Técnico de campo» se quedaba sin candidatos. Se devuelve todo el personal
 * con la bandera, y cada pantalla decide si filtra.
 *
 * QUÉ SALE Y QUÉ NO
 * -----------------
 * Nombre, rol y la bandera. NO sale el correo (`email` es el personal, no el de
 * acceso), ni el teléfono, ni los permisos: esto lo lee cualquiera con
 * `ticket_view`, no sólo quien administra personal.
 */
class SupportStaffOptionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        if (!$tenantId) {
            return response()->json(['data' => []]);
        }

        $staff = User::query()
            ->where('tenant_id', $tenantId)
            ->where('status', true)
            // Los abonados también son `users`: sin esto el desplegable de
            // técnicos ofrecía a toda la cartera de clientes.
            ->whereDoesntHave('customerProfile')
            // El rol propio tiene que resolver siempre, aunque sea global
            // (tenant_id NULL) o de otro tenant: ver la trampa del scope `tenant`.
            ->with(['role' => fn ($q) => $q->withoutGlobalScope('tenant')])
            ->orderBy('user_name')
            ->orderBy('user_lastname')
            ->get();

        return response()->json([
            'data' => $staff->map(fn (User $u) => [
                'id'            => $u->id,
                'name'          => $u->name,
                'user_name'     => $u->user_name,
                'user_lastname' => $u->user_lastname ?? '',
                'role_name'     => $u->role?->name,
                // El superadministrador (role_id 1) pasa por el bypass de
                // CheckPermission: también puede intervenir.
                'can_intervene' => (int) $u->role_id === 1
                    || $u->hasPermission(Permissions::TICKET_INTERVENE),
            ])->values(),
        ]);
    }
}
