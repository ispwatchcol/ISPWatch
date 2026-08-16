<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Models\ApiKeyRequestLog;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Traits\ValidatesIpAllowlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Alta, emisión y revocación de las llaves de la API pública.
 *
 * Vive en el panel (Configuración → Llaves API) y está reservado al tenant
 * operador: ver config/api_keys.php. La ruta ya exige `manage_api_keys`, pero
 * el permiso por sí solo no basta — lo tiene el rol Administrador de TODOS los
 * tenants, y un admin de un ISP no debe poder emitirse llaves ni, mucho menos,
 * emitirlas apuntando al tenant de otro.
 *
 * El texto plano de la llave se muestra UNA sola vez, al crearla. En la base
 * sólo queda el hash de Sanctum: si el integrador la pierde, se revoca y se
 * emite otra. No hay forma de recuperarla, y eso es lo correcto.
 */
class ApiClientController extends Controller
{
    // El operador no tiene tope de amplitud en la allowlist: emite conociendo la
    // plataforma y a veces necesita un bloque ancho de verdad. El auto-servicio
    // usa la variante estrecha del mismo trait (ver TenantApiKeyController).
    use ValidatesIpAllowlist;

    /**
     * Corta el acceso a quien no sea del tenant operador.
     *
     * Se ejecuta al principio de cada acción en vez de en un middleware propio
     * porque es una regla de este controlador y sólo de este: dispersarla en
     * un alias más haría más difícil ver que existe.
     */
    private function authorizeOperator(Request $request): void
    {
        $operatorTenant = (int) config('api_keys.operator_tenant_id');

        if ((int) ($request->user()->tenant_id ?? 0) !== $operatorTenant) {
            Log::warning('Intento de gestionar llaves de API desde un tenant no operador', [
                'user_id'   => $request->user()->id ?? null,
                'tenant_id' => $request->user()->tenant_id ?? null,
                'ip'        => $request->ip(),
            ]);

            abort(403, 'Sólo el tenant operador puede administrar llaves de API.');
        }
    }

    /** Clientes de API con sus llaves (nunca el texto plano). */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOperator($request);

        // withoutGlobalScope no aplica: ApiClient no usa BelongsToTenant. El
        // operador ve los clientes de todos los tenants a propósito — es quien
        // los da de alta.
        $clients = ApiClient::query()
            ->leftJoin('tenant', 'api_clients.tenant_id', '=', 'tenant.id')
            ->orderByDesc('api_clients.id')
            ->select([
                'api_clients.id',
                'api_clients.tenant_id',
                'api_clients.name',
                'api_clients.contact_email',
                'api_clients.description',
                'api_clients.is_active',
                'api_clients.created_at',
                'tenant.name as tenant_name',
            ])
            ->get();

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', ApiClient::class)
            ->orderByDesc('id')
            ->get()
            ->groupBy('tokenable_id');

        return response()->json([
            'data' => $clients->map(fn ($client) => [
                'id'            => (int) $client->id,
                'tenant_id'     => (int) $client->tenant_id,
                'tenant_name'   => $client->tenant_name,
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
            ]),
            'abilities' => config('api_keys.abilities'),
        ]);
    }

    /** Da de alta un consumidor externo y lo ata a un tenant. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeOperator($request);

        $data = $request->validate([
            'tenant_id'     => ['required', 'integer', Rule::exists('tenant', 'id')],
            'name'          => ['required', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'description'   => ['nullable', 'string', 'max:1000'],
        ]);

        $client = ApiClient::create($data + [
            'is_active'  => true,
            'created_by' => $request->user()->id,
        ]);

        Log::info('Cliente de API creado', [
            'api_client_id' => $client->id,
            'tenant_id'     => $client->tenant_id,
            'by_user_id'    => $request->user()->id,
        ]);

        return response()->json(['data' => $client], 201);
    }

    /** Renombra o activa/desactiva un cliente. El tenant NO se puede cambiar. */
    public function update(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorizeOperator($request);

        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        // `tenant_id` queda deliberadamente fuera del validate: moverlo
        // redirigiría todas las llaves vivas del cliente hacia otro tenant sin
        // que el integrador se entere. Para eso se crea otro cliente.
        $apiClient->update($data);

        return response()->json(['data' => $apiClient->fresh()]);
    }

    /**
     * Emite una llave. Única ocasión en que se ve el texto plano.
     */
    public function storeKey(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorizeOperator($request);

        $catalog = array_keys(config('api_keys.abilities'));

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'abilities'     => ['required', 'array', 'min:1'],
            'abilities.*'   => ['string', Rule::in($catalog)],
            'allowed_ips'   => ['required', 'array', 'min:1'],
            'allowed_ips.*' => ['string', 'max:64', $this->ipOrCidrRule()],
            'expires_at'    => ['nullable', 'date', 'after:now'],
        ]);

        if (!$apiClient->is_active) {
            return response()->json([
                'message' => 'El cliente está desactivado: actívalo antes de emitir llaves.',
            ], 422);
        }

        $expiresAt = $data['expires_at'] ?? null;

        $newToken = $apiClient->createToken(
            $data['name'],
            $data['abilities'],
            $expiresAt ? \Illuminate\Support\Carbon::parse($expiresAt) : null
        );

        $newToken->accessToken->forceFill([
            'allowed_ips' => array_values(array_unique($data['allowed_ips'])),
            'created_by'  => $request->user()->id,
        ])->save();

        Log::info('Llave de API emitida', [
            'token_id'      => $newToken->accessToken->id,
            'api_client_id' => $apiClient->id,
            'tenant_id'     => $apiClient->tenant_id,
            'abilities'     => $data['abilities'],
            'by_user_id'    => $request->user()->id,
        ]);

        return response()->json([
            'data' => [
                'id'          => (int) $newToken->accessToken->id,
                'name'        => $newToken->accessToken->name,
                'abilities'   => $data['abilities'],
                'allowed_ips' => $data['allowed_ips'],
                'expires_at'  => $newToken->accessToken->expires_at,

                // Se devuelve una sola vez. No se registra en ningún log.
                'plain_text_token' => $newToken->plainTextToken,
            ],
        ], 201);
    }

    /**
     * Revoca una llave.
     *
     * Se marca `revoked_at` y además se rompe el hash para que el token deje de
     * autenticar de inmediato, incluso si algo más adelante ignorara la marca.
     * La fila se conserva: la bitácora de peticiones la referencia por id y un
     * registro de auditoría que apunta a una llave borrada no sirve de nada.
     */
    public function destroyKey(Request $request, ApiClient $apiClient, int $tokenId): JsonResponse
    {
        $this->authorizeOperator($request);

        $token = PersonalAccessToken::query()
            ->where('id', $tokenId)
            ->where('tokenable_type', ApiClient::class)
            ->where('tokenable_id', $apiClient->id)
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

        Log::warning('Llave de API revocada', [
            'token_id'      => $token->id,
            'api_client_id' => $apiClient->id,
            'by_user_id'    => $request->user()->id,
        ]);

        return response()->json(['message' => 'Llave revocada.']);
    }

    /** Últimas peticiones del cliente: para auditar o depurar una integración. */
    public function logs(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorizeOperator($request);

        $request->validate(['limit' => 'sometimes|integer|min:1|max:200']);

        $logs = ApiKeyRequestLog::query()
            ->where('api_client_id', $apiClient->id)
            ->orderByDesc('id')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json(['data' => $logs]);
    }

    /** Tenants disponibles para el desplegable de alta. */
    public function tenants(Request $request): JsonResponse
    {
        $this->authorizeOperator($request);

        return response()->json([
            'data' => Tenant::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

}
