<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MikroTik\MikroTikConnectionManager;
use App\Services\MikroTik\OverlayReachabilityProbe;
use App\Services\MikroTik\RouterEndpointResolver;
use Illuminate\Console\Command;

/**
 * ¿Cuáles de mis routers están de verdad al otro lado del túnel?
 *
 *   php artisan router:probe-overlay              # toda la flota activa
 *   php artisan router:probe-overlay 62           # un router
 *   php artisan router:probe-overlay --tenant=16  # un tenant
 *
 * Un túnel "arriba" no significa que el equipo responda: el CORE puede tener la
 * sesión PPP activa y los contadores subiendo mientras el router del otro lado
 * ni siquiera se quedó con la dirección que se le asignó (caso CORE_SAN_ISIDRO,
 * 2026-08-18). Esta orden pregunta lo único que decide si ISPWatch va a poder
 * trabajar con ese equipo —¿contesta ÉL en su dirección?— antes de que el
 * técnico esté en el sitio del cliente descubriéndolo a mano.
 */
class ProbeRouterOverlay extends Command
{
    protected $signature = 'router:probe-overlay
                            {router_id? : Id del router; sin él, se prueban todos}
                            {--tenant= : Limitar a un tenant}
                            {--all-status : Incluir routers que no están en estado active}';

    protected $description = 'Verifica, router por router, si el equipo responde de verdad en su dirección del overlay';

    public function handle(RouterEndpointResolver $resolver): int
    {
        $routers = $this->routersToProbe();

        if ($routers->isEmpty()) {
            $this->warn('No hay routers que probar con ese filtro.');

            return self::SUCCESS;
        }

        $connection = new MikroTikConnectionManager();

        $this->info('Comprobando SSH al CORE...');
        $ssh = $connection->testSshConnection(10);
        $this->line('  ' . ($ssh['success'] ? 'OK — ' . ($ssh['identity'] ?? '') : 'FALLA — ' . ($ssh['message'] ?? '')));

        if (!($ssh['success'] ?? false)) {
            $this->error('Sin SSH al CORE no se puede sondear nada. Corrige primero servidor→CORE.');

            return self::FAILURE;
        }

        $this->newLine();

        $probe = new OverlayReachabilityProbe($connection);
        $rows  = [];
        $roto  = 0;
        $mudo  = 0;

        foreach ($routers as $router) {
            // La dirección buena es la que el CORE está usando ahora mismo, no la
            // que quedó guardada: con L2TP cambia en cada reconexión. El resolver
            // la corrige en la BD de paso, que es lo que hace la UI también.
            $endpoint = $resolver->resolve($router);
            $ip       = $endpoint['ip'];

            if ($ip === '') {
                $rows[] = [$router->id, $router->name, $router->vpnTransport(), $router->firmware_version, '—', 'SIN IP', 'el router no tiene dirección registrada'];
                $roto++;
                continue;
            }

            $result = $probe->probe($ip);
            [$estado, $detalle] = $this->verdict($result, $ip);

            if ($probe->isConclusiveFailure($result)) {
                $roto++;
            } elseif ($result['state'] === OverlayReachabilityProbe::STATE_SILENT) {
                $mudo++;
            }

            $rows[] = [
                $router->id,
                $router->name,
                $router->vpnTransport(),
                $router->firmware_version ?: '—',
                $ip . ($endpoint['drifted'] ? ' (corregida)' : ''),
                $estado,
                $detalle,
            ];
        }

        $this->table(['id', 'router', 'transporte', 'fw', 'ip overlay', 'estado', 'detalle'], $rows);

        if ($roto > 0) {
            $this->error("{$roto} router(es) NO responden en su dirección: ISPWatch no puede administrarlos hasta corregirlo en el equipo.");
        }

        if ($mudo > 0) {
            $this->warn("{$mudo} router(es) no contestan ping. Puede ser ICMP filtrado a propósito — verifica con router:diagnose-wan <id> si además falla la lectura.");
        }

        if ($roto === 0 && $mudo === 0) {
            $this->info('Todos los routers responden en su dirección del overlay.');
        }

        return $roto > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Router> */
    private function routersToProbe()
    {
        // Sin scope de tenant: esta orden se corre desde la consola del operador
        // del SaaS, que necesita ver la flota entera.
        $query = Router::withoutGlobalScopes()->orderBy('tenant_id')->orderBy('id');

        if ($id = $this->argument('router_id')) {
            $query->where('id', (int) $id);
        }

        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenant);
        }

        if (!$this->option('all-status') && !$this->argument('router_id')) {
            $query->where('status', 'active');
        }

        return $query->get();
    }

    /** @return array{0: string, 1: string} */
    private function verdict(array $result, string $ip): array
    {
        // "sin sesión" sólo es noticia cuando algo falla: un router WireGuard
        // nunca aparece en /ppp active y anunciarlo como carencia asustaría sin
        // motivo a quien mira la tabla.
        $sesion = $result['has_session'] ? 'sesión VPN activa' : 'sin sesión VPN (normal en WireGuard)';

        return match ($result['state']) {
            OverlayReachabilityProbe::STATE_ALIVE => ['RESPONDE', 'el equipo contesta en su dirección'],

            OverlayReachabilityProbe::STATE_FOREIGN_HOP => $result['in_overlay']
                ? ['NO ES ÉL', "contesta {$result['hop']}: el equipo no se quedó con {$ip} y reenvía los paquetes"]
                : ['SIN RUTA', "contesta {$result['hop']}: el CORE no tiene túnel hacia {$ip} (¿router desconectado?)"],

            OverlayReachabilityProbe::STATE_SILENT => ['MUDO', "{$sesion}; no contesta ping (puede ser ICMP filtrado)"],

            default => ['?', 'el CORE no devolvió una respuesta legible'],
        };
    }
}
