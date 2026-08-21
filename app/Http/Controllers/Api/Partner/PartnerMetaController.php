<?php

namespace App\Http\Controllers\Api\Partner;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
    /**
     * Ruta del contrato OpenAPI, relativa a la raíz del proyecto.
     *
     * Vive en `docs/` y no en `public/` a propósito: es documentación del
     * proyecto —se revisa en el mismo PR que el código, como el resto de
     * `docs/`— y servirla desde aquí evita mantener una segunda copia que
     * tarde o temprano se desincroniza de la primera.
     */
    public const SPEC_PATH = 'docs/openapi/ispwatch-partner-v1.yaml';

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
                'your_ip'    => $request->realIp(),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Devuelve el contrato OpenAPI que describe esta misma API.
     *
     * Se sirve desde el despliegue y no sólo por correo por una razón práctica:
     * el archivo que tiene el integrador y el código que está corriendo se
     * separan en cuanto alguien reenvía una versión vieja. Pidiéndolo aquí, lo
     * que recibe es por definición el del despliegue que le está respondiendo.
     *
     * Va sin `ability` —igual que `ping`— porque describir la superficie de la
     * API no revela ningún dato del ISP: sólo la forma de lo que la llave ya
     * puede pedir. Sigue exigiendo llave válida, IP autorizada y HTTPS, como
     * todo lo demás del grupo.
     */
    public function spec(): Response
    {
        $path = base_path(self::SPEC_PATH);

        // Sin el archivo no hay contrato que servir. Se responde con el mismo
        // formato de error que el resto de la API para que el integrador no
        // tenga que interpretar un HTML de Laravel.
        if (!is_file($path)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'La especificación OpenAPI no está disponible en este despliegue.',
            ], 404);
        }

        return response(file_get_contents($path), 200, [
            'Content-Type'        => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="ispwatch-partner-v1.yaml"',
        ]);
    }
}
