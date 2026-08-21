<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impide que una llave de la API pública alcance las rutas del panel.
 *
 * Un token de Sanctum autentica en CUALQUIER ruta protegida por `auth:sanctum`,
 * no sólo en aquella para la que se emitió. Sin esta barrera, la llave de
 * lectura de un integrador serviría también para `/api/customers`,
 * `/api/dashboard/stats` o incluso las rutas de aprovisionamiento — con los
 * campos completos y sin la allowlist de IPs.
 *
 * Va en el grupo `auth:sanctum` de routes/api.php, antes de cualquier
 * `permission:`, para que el rechazo ocurra sin tocar la lógica de permisos
 * (que además no sabría qué hacer con un modelo sin rol).
 */
class DenyApiClients
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof ApiClient) {
            Log::warning('Llave de API usada contra una ruta del panel', [
                'client_id' => $request->user()->getKey(),
                'tenant_id' => $request->user()->tenant_id,
                'path'      => $request->path(),
                'ip'        => $request->realIp(),
            ]);

            return response()->json([
                'error'   => 'endpoint_not_available',
                'message' => 'Las llaves de API sólo pueden usarse en /api/v1/partner.',
            ], 403);
        }

        return $next($request);
    }
}
