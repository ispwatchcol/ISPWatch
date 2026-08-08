<?php

namespace App\Http\Controllers\Api\Partner;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint de verificación de la llave.
 *
 * Existe para que el integrador pueda confirmar en un solo GET que su llave
 * quedó bien configurada — que la IP desde la que sale está autorizada y qué
 * permisos le concedieron — sin tener que adivinar entre un 403 de allowlist y
 * uno de abilities mientras depura su integración.
 *
 * No expone datos de negocio: sólo la identidad de la propia llave.
 */
class PartnerMetaController extends PartnerController
{
    public function ping(Request $request): JsonResponse
    {
        $client = $request->user();
        $token  = $client->currentAccessToken();

        return response()->json([
            'data' => [
                'client'     => $client->name,
                'tenant_id'  => (int) $client->tenant_id,
                'key_name'   => $token->name,
                'abilities'  => $token->abilities ?? [],
                'expires_at' => $token->expires_at,
                'your_ip'    => $request->ip(),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }
}
