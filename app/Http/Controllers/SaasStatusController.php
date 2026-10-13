<?php

namespace App\Http\Controllers;

use App\Services\Saas\ConverzaServiceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/saas/status` — ¿el panel de quien pregunta está cortado? (KAN-126)
 *
 * Lo usa el panel al montarse y la pantalla de «servicio suspendido» en su botón
 * «Ya pagué». Por eso es la única ruta del panel, junto a /auth/me, que no pasa
 * por EnsureSaasServiceActive: si respondiera 423, nadie podría saber cuándo
 * volver a entrar.
 *
 * Responde lo mismo que vería el middleware, recordado igual (ver
 * ConverzaServiceStatus): pulsar el botón no llama a Converza cada vez.
 */
class SaasStatusController extends Controller
{
    public function show(Request $request, ConverzaServiceStatus $status): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $status->forUser($request->user()),
        ]);
    }
}
