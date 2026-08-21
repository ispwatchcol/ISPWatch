<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\ApiKeyRequestLog;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Puerta única de la API pública de solo lectura (/api/v1/partner/*).
 *
 * Todo lo que separa a un integrador externo de la base de datos pasa por aquí.
 * El orden de las comprobaciones no es casual: primero lo que se resuelve sin
 * tocar la base (verbo, esquema), después la identidad, y sólo al final lo que
 * exige leer el token.
 *
 * Cada rechazo devuelve un `error` estable y legible por máquina para que el
 * integrador pueda distinguir "mi IP cambió" de "me revocaron la llave" sin
 * tener que llamar por teléfono.
 *
 * Fallar cerrado es la regla: cualquier condición que no se pueda verificar
 * con certeza (cliente sin tenant, token que no es de esta API, allowlist
 * vacía) termina en rechazo, nunca en "déjalo pasar por si acaso".
 */
class EnsureApiKeyRequest
{
    /** Verbos admitidos. La API es de lectura: no hay excepciones. */
    private const READ_ONLY_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        if ($denial = $this->deny($request)) {
            [$reason, $status, $message] = $denial;
            $this->log($request, $status, $startedAt, $reason);

            return response()->json([
                'error'   => $reason,
                'message' => $message,
            ], $status);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Illuminate\Routing\Pipeline suele renderizar las excepciones de
            // los middleware siguientes y devolverlas ya como respuesta, así
            // que la mayoría de los rechazos se registran por la vía normal.
            // Esto cubre lo que sí llegue a escaparse.
            $status = $this->statusOf($e);
            $this->log($request, $status, $startedAt, $this->reasonFor($status));

            throw $e;
        }

        $status = $response->getStatusCode();
        $this->log($request, $status, $startedAt, $this->reasonFor($status));

        return $response;
    }

    /**
     * Clasifica el desenlace para la bitácora.
     *
     * Un 403 aquí abajo sólo puede venir del middleware de abilities (los
     * rechazos propios ya salieron antes con su motivo), así que se nombra por
     * lo que realmente pasó: la llave existe y es válida, pero no tiene el
     * permiso de lectura del área que pidió.
     */
    private function reasonFor(int $status): ?string
    {
        return match (true) {
            $status < 400 => null,
            $status === 403 => 'missing_ability',
            $status === 404 => 'not_found',
            $status === 422 => 'invalid_parameters',
            $status === 429 => 'rate_limited',
            default => 'denied',
        };
    }

    /** Código HTTP con el que la excepción va a terminar respondiendo. */
    private function statusOf(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof ValidationException) {
            return $e->status;
        }

        return 500;
    }

    /**
     * Devuelve [motivo, status, mensaje] si hay que rechazar; null si pasa.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function deny(Request $request): ?array
    {
        if (!in_array($request->getMethod(), self::READ_ONLY_METHODS, true)) {
            return ['method_not_allowed', 405, 'Esta API es de solo lectura: únicamente se admite GET.'];
        }

        // En producción la llave viaja en un header: sobre HTTP plano queda
        // expuesta en cualquier salto intermedio. Se rechaza antes de mirarla
        // siquiera, y quien llegue así debe considerar su llave comprometida.
        if (app()->environment('production') && !$request->isSecure()) {
            return ['https_required', 403, 'La API sólo se atiende sobre HTTPS.'];
        }

        $client = $request->user();

        // No es un ApiClient: o es una sesión del panel (cookie), o un token de
        // usuario humano. Ninguno de los dos tiene nada que hacer en esta API,
        // y dejarlos pasar sería colar aquí las reglas de permisos del panel.
        if (!$client instanceof ApiClient) {
            return ['invalid_credentials', 401, 'Credenciales no válidas para la API pública.'];
        }

        if (!$client->is_active) {
            return ['client_disabled', 403, 'El cliente de API está desactivado.'];
        }

        // Un ApiClient sin tenant no tiene frontera: el global scope de
        // BelongsToTenant no filtra cuando tenant_id es null, así que vería la
        // base entera. Es el peor fallo posible de este diseño y por eso se
        // corta aquí, antes de llegar a cualquier consulta.
        if (!$client->tenant_id) {
            return ['tenant_missing', 403, 'El cliente de API no tiene tenant asignado.'];
        }

        $token = $client->currentAccessToken();

        // TransientToken (sesión con cookie) devuelve true en cualquier
        // tokenCan(): admitirlo anularía por completo el control de abilities.
        if (!$token instanceof PersonalAccessToken) {
            return ['invalid_credentials', 401, 'Se requiere una llave de API (Bearer token).'];
        }

        if ($token->isRevoked()) {
            return ['key_revoked', 401, 'Esta llave de API fue revocada.'];
        }

        // Sanctum ya rechaza los tokens caducados en el guard; se repite aquí
        // para que la caducidad no dependa de una sola capa.
        if ($token->expires_at && $token->expires_at->isPast()) {
            return ['key_expired', 401, 'Esta llave de API está vencida.'];
        }

        if (!$token->allowsIp($request->realIp())) {
            Log::warning('API key usada desde una IP no autorizada', [
                'token_id'  => $token->getKey(),
                'client_id' => $client->getKey(),
                'tenant_id' => $client->tenant_id,
                'ip'        => $request->realIp(),
            ]);

            return ['ip_not_allowed', 403, 'La IP de origen no está autorizada para esta llave.'];
        }

        $this->rememberSourceIp($token, $request->realIp());

        return null;
    }

    /**
     * Guarda la última IP vista, sólo cuando cambia.
     *
     * Se escribe con el query builder y sin tocar `updated_at`: es telemetría,
     * no una modificación de la llave, y una escritura por petición sobre una
     * tabla que se lee en cada request no aporta nada bueno.
     */
    private function rememberSourceIp(PersonalAccessToken $token, ?string $ip): void
    {
        if (!$ip || $token->last_used_ip === $ip) {
            return;
        }

        try {
            DB::table($token->getTable())
                ->where('id', $token->getKey())
                ->update(['last_used_ip' => $ip]);
        } catch (Throwable $e) {
            // La telemetría nunca debe tumbar la petición del cliente.
            Log::warning('No se pudo registrar last_used_ip de la llave de API', [
                'token_id' => $token->getKey(),
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Escribe la bitácora de la petición.
     *
     * Nunca lanza: si el log falla, el cliente igual recibe su respuesta. Un
     * problema de auditoría no puede convertirse en una caída del servicio.
     */
    private function log(Request $request, int $status, float $startedAt, ?string $reason = null): void
    {
        try {
            $client = $request->user();
            $token  = $client instanceof ApiClient ? $client->currentAccessToken() : null;

            ApiKeyRequestLog::create([
                'api_client_id' => $client instanceof ApiClient ? $client->getKey() : null,
                'token_id'      => $token instanceof PersonalAccessToken ? $token->getKey() : null,
                'tenant_id'     => $client instanceof ApiClient ? $client->tenant_id : null,
                'method'        => substr($request->getMethod(), 0, 10),
                'path'          => substr($request->path(), 0, 255),
                'ip'            => substr((string) $request->realIp(), 0, 45),
                'status_code'   => $status,
                'duration_ms'   => (int) round((microtime(true) - $startedAt) * 1000),
                'denied_reason' => $reason ? substr($reason, 0, 50) : null,
                'created_at'    => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar la petición de la API pública', [
                'path'  => $request->path(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
