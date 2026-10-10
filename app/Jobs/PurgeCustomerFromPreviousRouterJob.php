<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Router;
use App\Services\MikroTik\CustomerDeprovisionManager;
use App\Services\MikroTik\RouterEndpointResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Borra del router ANTERIOR la configuración de un cliente que se mudó de
 * router (KAN-119).
 *
 * Antes la edición aprovisionaba en el router nuevo y no tocaba el viejo: la
 * cola, el secret, el lease y la entrada en ISPWATCH_SUSPENDIDOS se quedaban
 * allí. El cliente podía seguir navegando por el equipo viejo, y la IP quedaba
 * ocupada si después se le asignaba a otro.
 *
 * En cola y no dentro del request: cada viaje al CORE cuesta ~15 s.
 *
 * NO BORRA LO QUE HOY ES DE OTRO
 * ------------------------------
 * La identidad es la que el cliente tenía en el router viejo. Si entre la
 * mudanza y la ejecución otro cliente de ese router quedó con la misma IP,
 * usuario PPPoE/HotSpot o MAC (por ejemplo, un intercambio en la misma carga
 * masiva), ese dato se omite: el barrido borra por esas claves y se llevaría
 * la configuración del otro.
 *
 * El resultado queda en la bitácora de auditoría del panel, éxito o fallo: un
 * router viejo sin limpiar no puede ser silencioso.
 */
class PurgeCustomerFromPreviousRouterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un intento: el barrido es idempotente, pero reintentar a ciegas multiplica los timeouts. */
    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param array{ip:?string,pppoe_username:?string,hotspot_username:?string,mac_address:?string} $identity
     */
    public function __construct(
        public int $routerId,
        public int $customerId,
        public int $tenantId,
        public array $identity,
    ) {
    }

    public function handle(CustomerDeprovisionManager $deprovision, RouterEndpointResolver $endpoints): void
    {
        $router = Router::withoutGlobalScope('tenant')->find($this->routerId);

        // Se vuelve a mirar al ejecutar: el router pudo pasar a RADIUS o perder
        // sus credenciales desde que se encoló.
        if (!$router || $router->usesRadius() || !$router->user_rb || !$router->password_rb) {
            return;
        }

        $identity = $this->identityStillOwned();

        try {
            $endpoint = $endpoints->resolve($router);

            $result = $deprovision->purge(
                $endpoint['ip'],
                $router->user_rb,
                $router->password_rb,
                $identity,
                $endpoint['ssh_port']
            );
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => 'No se pudo contactar al router: ' . $e->getMessage()];
        }

        $this->record($router, $result);
    }

    public function failed(\Throwable $e): void
    {
        $router = Router::withoutGlobalScope('tenant')->find($this->routerId);

        $this->record($router, ['success' => false, 'message' => 'La limpieza falló: ' . $e->getMessage()]);
    }

    /**
     * La identidad del cliente menos lo que hoy usa otro cliente del mismo
     * router.
     */
    private function identityStillOwned(): array
    {
        $columns = [
            'ip'               => 'ip_user',
            'pppoe_username'   => 'pppoe_username',
            'hotspot_username' => 'hotspot_username',
            'mac_address'      => 'mac_address',
        ];

        $identity = [];

        foreach ($columns as $key => $column) {
            $value = $this->identity[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $takenByOther = CustomerProfile::where('router_id', $this->routerId)
                ->where($column, $value)
                ->where('user_id', '!=', $this->customerId)
                ->exists();

            if (!$takenByOther) {
                $identity[$key] = $value;
            }
        }

        return $identity;
    }

    private function record(?Router $router, array $result): void
    {
        $ok   = (bool) ($result['success'] ?? false);
        $name = $router?->name ?? "#{$this->routerId}";

        if (!$ok) {
            Log::warning('[PurgeCustomerFromPreviousRouterJob] El router anterior quedó sin limpiar', [
                'router_id'   => $this->routerId,
                'customer_id' => $this->customerId,
                'message'     => $result['message'] ?? null,
            ]);
        }

        AuditLog::log([
            'tenant_id'   => $this->tenantId,
            'action'      => $ok ? 'customer.previous_router_cleaned' : 'customer.previous_router_cleanup_failed',
            'model_type'  => CustomerProfile::class,
            'model_id'    => $this->customerId,
            'new_values'  => [
                'router_id' => $this->routerId,
                'success'   => $ok,
                'skipped'   => (bool) ($result['skipped'] ?? false),
            ],
            'description' => $ok
                ? "Configuración del cliente retirada del router anterior {$name} tras el cambio de router."
                : "No se pudo retirar la configuración del cliente del router anterior {$name}: "
                    . ($result['message'] ?? 'motivo desconocido') . '. Hay que retirarla a mano.',
        ]);
    }
}
