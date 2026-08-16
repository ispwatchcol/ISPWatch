<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Models\ApiKeyRequestLog;
use App\Models\PersonalAccessToken;
use App\Traits\ValidatesIpAllowlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Auto-servicio de llaves de API: el ISP emite las suyas desde su panel.
 *
 * Camino paralelo a ApiClientController, deliberadamente separado y no una
 * rama dentro de aquél. La razón es que los dos tienen modelos de autorización
 * incompatibles: allí el permiso es "administrar las llaves de CUALQUIER
 * tenant" y aquí es "administrar las de ESTE tenant y ninguno más". Mezclarlos
 * en una clase con condicionales convertiría cada método en una pregunta sobre
 * quién llama, y ese es justo el tipo de código donde se cuela un caso que
 * nadie contempló.
 *
 * Invariante central: `tenant_id` sale SIEMPRE de la sesión, nunca de la
 * petición, y toda consulta se filtra por él antes de tocar nada. Un id de otro
 * tenant resuelve 404, indistinguible de uno inexistente.
 *
 * Los límites (qué permisos, cuántas llaves, cuánto duran, qué tan ancha puede
 * ser la allowlist de IP) están en config/api_keys.php con el porqué de cada
 * uno. Aquí sólo se aplican.
 */
class TenantApiKeyController extends Controller
{
    use ValidatesIpAllowlist;

    /**
     * Corta si el auto-servicio está apagado.
     *
     * El interruptor es de configuración y no de permiso a propósito: apagarlo
     * debe devolver la emisión al operador sin tener que tocar los roles de
     * cada ISP uno por uno.
     */
    private function assertEnabled(): void
    {
        abort_unless(
            (bool) config('api_keys.self_service.enabled'),
            403,
            'La emisión de llaves por auto-servicio está desactivada. Solicítala al operador.'
        );
    }

    /** El tenant de quien llama. Nunca se acepta de la petición. */
    private function tenantId(Request $request): int
    {
        $tenantId = (int) ($request->user()->tenant_id ?? 0);

        abort_if($tenantId === 0, 403, 'Tu usuario no tiene empresa asignada.');

        return $tenantId;
    }

    /** Consumidores de API de este tenant, y sólo de este. */
    private function clientsOf(int $tenantId)
    {
        return ApiClient::query()->where('tenant_id', $tenantId);
    }

    /**
     * Resuelve un cliente propio o falla con 404.
     *
     * 404 y no 403: el id de otro tenant tiene que ser indistinguible de uno que
     * no existe, o la propia diferencia de códigos confirma que el recurso está
     * ahí y permite enumerar los consumidores de la competencia.
     */
    private function ownClientOrFail(Request $request, int $clientId): ApiClient
    {
        $client = $this->clientsOf($this->tenantId($request))->whereKey($clientId)->first();

        abort_if(!$client, 404, 'Cliente de API no encontrado.');

        return $client;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Lo que el ISP ve: sus consumidores, sus llaves y sus propios límites.
     *
     * Los límites viajan en la respuesta para que el formulario los muestre
     * ANTES de que el usuario llene nada. Que el servidor rechace un /8 es
     * correcto; que el panel no avise de que existe el límite hasta después de
     * escribirlo es una forma tonta de gastarle el tiempo a alguien.
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $tenantId = $this->tenantId($request);
        $clients  = $this->clientsOf($tenantId)->orderByDesc('id')->get();

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', ApiClient::class)
            ->whereIn('tokenable_id', $clients->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('tokenable_id');

        $catalogo = config('api_keys.abilities');
        $propias  = (array) config('api_keys.self_service.abilities');

        return response()->json([
            'data' => $clients->map(fn (ApiClient $client) => [
                'id'            => (int) $client->id,
                'name'          => $client->name,
                'contact_email' => $client->contact_email,
                'description'   => $client->description,
                'is_active'     => (bool) $client->is_active,
                'created_at'    => $client->created_at,
                'keys'          => ($tokens[$client->id] ?? collect())->map(fn ($token) => [
                    'id'           => (int) $token->id,
                    'name'         => $token->name,
                    'abilities'    => $token->abilities ?? [],
                    'allowed_ips'  => $token->allowed_ips ?? [],
                    'last_used_at' => $token->last_used_at,
                    'last_used_ip' => $token->last_used_ip,
                    'expires_at'   => $token->expires_at,
                    'revoked_at'   => $token->revoked_at,
                    'created_at'   => $token->created_at,
                ])->values(),
            ])->values(),

            // Sólo las abilities que este camino puede conceder: el catálogo
            // completo incluye read:billing, que aquí no se ofrece.
            'abilities' => array_intersect_key($catalogo, array_flip($propias)),

            'limits' => [
                'max_active_keys'     => (int) config('api_keys.self_service.max_active_keys'),
                'active_keys'         => $this->activeKeyCount($tenantId),
                'max_clients'         => (int) config('api_keys.self_service.max_clients'),
                'clients'             => $clients->count(),
                'max_expiration_days' => (int) config('api_keys.self_service.max_expiration_days'),
                'min_ipv4_prefix'     => (int) config('api_keys.self_service.min_ipv4_prefix'),
                'min_ipv6_prefix'     => (int) config('api_keys.self_service.min_ipv6_prefix'),
            ],
        ]);
    }

    /** Da de alta un consumidor propio. El tenant no es negociable. */
    public function store(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $tenantId = $this->tenantId($request);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'description'   => ['nullable', 'string', 'max:1000'],
        ]);

        $max = (int) config('api_keys.self_service.max_clients');

        if ($this->clientsOf($tenantId)->count() >= $max) {
            throw ValidationException::withMessages([
                'name' => "Ya tienes {$max} integraciones registradas, que es el máximo. "
                    . 'Elimina o reutiliza una existente, o solicítale al operador que amplíe el límite.',
            ]);
        }

        $client = ApiClient::create($data + [
            // Nunca de $request: es la línea que impide emitir hacia otro tenant.
            'tenant_id'  => $tenantId,
            'is_active'  => true,
            'created_by' => $request->user()->id,
        ]);

        Log::info('Cliente de API creado por auto-servicio', [
            'api_client_id' => $client->id,
            'tenant_id'     => $tenantId,
            'by_user_id'    => $request->user()->id,
        ]);

        return response()->json(['data' => $client], 201);
    }

    /**
     * Emite una llave propia. Única ocasión en que se ve el texto plano.
     */
    public function storeKey(Request $request, int $clientId): JsonResponse
    {
        $this->assertEnabled();

        $tenantId = $this->tenantId($request);
        $client   = $this->ownClientOrFail($request, $clientId);

        $permitidas = (array) config('api_keys.self_service.abilities');
        $maxDias    = (int) config('api_keys.self_service.max_expiration_days');

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'abilities'   => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in($permitidas)],

            'allowed_ips'   => ['required', 'array', 'min:1', 'max:20'],
            'allowed_ips.*' => [
                'string',
                'max:64',
                $this->narrowIpOrCidrRule(
                    (int) config('api_keys.self_service.min_ipv4_prefix'),
                    (int) config('api_keys.self_service.min_ipv6_prefix'),
                ),
            ],

            // Obligatorio, a diferencia del camino del operador: una llave sin
            // caducidad emitida por quien no administra la plataforma no la va
            // a rotar nadie.
            'expires_at' => [
                'required',
                'date',
                'after:now',
                'before_or_equal:' . now()->addDays($maxDias)->toDateTimeString(),
            ],
        ], [
            'expires_at.required'        => 'Indica cuándo vence la llave.',
            'expires_at.before_or_equal' => "La vigencia máxima en auto-servicio es de {$maxDias} días.",
            'abilities.*.in'             => 'Ese permiso no se puede conceder desde aquí; solicítalo al operador.',
        ]);

        if (!$client->is_active) {
            return response()->json([
                'message' => 'Esta integración está desactivada: actívala antes de emitir llaves.',
            ], 422);
        }

        $activas = $this->activeKeyCount($tenantId);
        $maxLlaves = (int) config('api_keys.self_service.max_active_keys');

        if ($activas >= $maxLlaves) {
            throw ValidationException::withMessages([
                'name' => "Ya tienes {$activas} llaves vigentes, que es el máximo ({$maxLlaves}). "
                    . 'Revoca una que ya no uses antes de emitir otra.',
            ]);
        }

        $ips = array_values(array_unique($data['allowed_ips']));

        $newToken = $client->createToken(
            $data['name'],
            $data['abilities'],
            Carbon::parse($data['expires_at'])
        );

        $newToken->accessToken->forceFill([
            'allowed_ips' => $ips,
            'created_by'  => $request->user()->id,
        ])->save();

        // Sin el texto plano, aquí ni en ningún otro log.
        Log::notice('Llave de API emitida por auto-servicio', [
            'token_id'      => $newToken->accessToken->id,
            'api_client_id' => $client->id,
            'tenant_id'     => $tenantId,
            'abilities'     => $data['abilities'],
            'allowed_ips'   => $ips,
            'expires_at'    => $data['expires_at'],
            'by_user_id'    => $request->user()->id,
        ]);

        $this->notifyOperator($request, $client, $data, $ips);

        return response()->json([
            'data' => [
                'id'          => (int) $newToken->accessToken->id,
                'name'        => $newToken->accessToken->name,
                'abilities'   => $data['abilities'],
                'allowed_ips' => $ips,
                'expires_at'  => $newToken->accessToken->expires_at,

                // Se devuelve una sola vez. No se registra en ningún log.
                'plain_text_token' => $newToken->plainTextToken,
            ],
        ], 201);
    }

    /**
     * Revoca una llave propia.
     *
     * Se marca `revoked_at` y además se rompe el hash, igual que en el camino
     * del operador: revocar tiene que surtir efecto de inmediato aunque algo más
     * adelante ignorara la marca.
     */
    public function destroyKey(Request $request, int $clientId, int $tokenId): JsonResponse
    {
        $this->assertEnabled();

        $client = $this->ownClientOrFail($request, $clientId);

        $token = PersonalAccessToken::query()
            ->where('id', $tokenId)
            ->where('tokenable_type', ApiClient::class)
            ->where('tokenable_id', $client->id)
            ->first();

        if (!$token) {
            return response()->json(['message' => 'Llave no encontrada.'], 404);
        }

        if ($token->isRevoked()) {
            return response()->json(['message' => 'La llave ya estaba revocada.'], 200);
        }

        $token->forceFill([
            'revoked_at' => now(),
            'token'      => hash('sha256', 'revoked:' . $token->id . ':' . bin2hex(random_bytes(16))),
        ])->save();

        Log::warning('Llave de API revocada por auto-servicio', [
            'token_id'      => $token->id,
            'api_client_id' => $client->id,
            'tenant_id'     => $client->tenant_id,
            'by_user_id'    => $request->user()->id,
        ]);

        return response()->json(['message' => 'Llave revocada.']);
    }

    /**
     * Bitácora de peticiones de una integración propia.
     *
     * Es la mitad del auto-servicio que de verdad ahorra soporte: casi todas
     * las consultas de un integrador se contestan viendo aquí un 403
     * `ip_not_allowed` o un 401 `key_expired`.
     */
    public function logs(Request $request, int $clientId): JsonResponse
    {
        $this->assertEnabled();

        $client = $this->ownClientOrFail($request, $clientId);

        $request->validate(['limit' => 'sometimes|integer|min:1|max:200']);

        $logs = ApiKeyRequestLog::query()
            ->where('api_client_id', $client->id)
            ->orderByDesc('id')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json(['data' => $logs]);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Llaves realmente utilizables del tenant: ni revocadas ni vencidas.
     *
     * Contar las revocadas inflaría el tope y dejaría al ISP bloqueado por
     * llaves que ya no sirven para nada.
     */
    private function activeKeyCount(int $tenantId): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', ApiClient::class)
            ->whereIn('tokenable_id', $this->clientsOf($tenantId)->select('id'))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    /**
     * Avisa al operador de que se emitió una llave.
     *
     * El operador deja de autorizar, pero no puede dejar de enterarse: una
     * llave nueva cambia qué datos salen de la plataforma. Nunca lanza — que
     * falle el correo no puede impedirle al ISP emitir su llave, y el registro
     * en el log ya quedó escrito antes de llegar aquí.
     */
    private function notifyOperator(Request $request, ApiClient $client, array $data, array $ips): void
    {
        $destino = config('api_keys.self_service.notify_email');

        if (!$destino) {
            return;
        }

        try {
            $usuario = $request->user();

            Mail::raw(
                implode("\n", [
                    'Se emitió una llave de API por auto-servicio.',
                    '',
                    'Empresa (tenant): ' . $client->tenant_id,
                    'Integración:      ' . $client->name,
                    'Llave:            ' . $data['name'],
                    'Permisos:         ' . implode(', ', $data['abilities']),
                    'IPs permitidas:   ' . implode(', ', $ips),
                    'Vence:            ' . $data['expires_at'],
                    'Emitida por:      ' . ($usuario->email ?? $usuario->id),
                    '',
                    'Este mensaje es informativo: la llave ya está activa.',
                ]),
                fn ($mensaje) => $mensaje->to($destino)
                    ->subject('[ISPWatch] Llave de API emitida por auto-servicio')
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar al operador de una llave de auto-servicio', [
                'api_client_id' => $client->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
