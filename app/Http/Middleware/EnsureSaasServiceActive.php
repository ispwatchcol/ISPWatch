<?php

namespace App\Http\Middleware;

use App\Services\Saas\ConverzaServiceStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le cierra el panel al equipo de un ISP que Converza marcó como suspendido
 * (KAN-126).
 *
 * Va en el grupo `auth:sanctum` de routes/api.php, junto a `deny_api_clients`,
 * para que ningún endpoint nuevo del panel se quede sin él. Fuera de ese grupo
 * —y por lo tanto intactos— quedan la API partner, el login, la firma remota del
 * contrato y todo lo que corre en el planificador: el corte es del panel, no del
 * servicio a los clientes finales.
 *
 * Responde 423 con `code: service_suspended` y el texto que publica Converza.
 * El frontend (services/api.js) reconoce ese código y cambia el panel entero
 * por la pantalla de «servicio suspendido».
 *
 * Quedan abiertas, con `withoutMiddleware('saas_active')`:
 *   · GET /api/saas/status → la pantalla pregunta si ya puede volver.
 *   · GET /api/auth/me     → la sesión sigue identificada y se puede cerrar.
 * Cerrar sesión no necesita excepción: es del navegador (stores/auth.js).
 */
class EnsureSaasServiceActive
{
    public function __construct(private readonly ConverzaServiceStatus $status)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->status->forUser($request->user());

        if (! $status['suspended']) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code'    => 'service_suspended',
            'message' => $status['message'],
            'data'    => $status,
        ], 423);
    }
}
