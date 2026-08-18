<?php

namespace Tests\Unit;

use App\Services\MikroTik\MikroTikConnectionManager;
use App\Services\MikroTik\OverlayReachabilityProbe;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Las salidas de /ping de estas pruebas son COPIAS LITERALES de lo que contestó
 * el CORE de producción (CHR-ISPWATCH, RouterOS 7.22.2) el 2026-08-18, tanto
 * para el router sano (CORE_TOCAIMA, 172.16.16.254) como para el averiado
 * (CORE_SAN_ISIDRO, 172.16.16.253). Si alguien cambia el parser, tiene que
 * seguir leyendo exactamente ese formato — no una versión idealizada.
 */
class OverlayReachabilityProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('log', new class
        {
            public function info(...$args): void
            {
            }

            public function warning(...$args): void
            {
            }

            public function error(...$args): void
            {
            }
        });

        Facade::setFacadeApplication($container);
    }

    public function test_router_that_answers_is_alive(): void
    {
        $probe = $this->probeWith("ISP_ROUTE:1\nISP_PPP:1\n" . <<<'PING'
  SEQ HOST                                     SIZE TTL TIME       STATUS
    0 172.16.16.254                              56  64 73ms25us
    1 172.16.16.254                              56  64 72ms651us
    sent=2 received=2 packet-loss=0% min-rtt=72ms651us avg-rtt=72ms838us
PING)->probe('172.16.16.254');

        $this->assertSame(OverlayReachabilityProbe::STATE_ALIVE, $probe['state']);
        $this->assertNull($probe['hop']);
        $this->assertTrue($probe['in_overlay']);
    }

    public function test_a_third_party_answering_means_nobody_owns_that_address(): void
    {
        // El caso real: el túnel está arriba (ruta y sesión presentes) pero el
        // equipo del otro lado reenvía nuestro paquete a su propio gateway.
        $probe = $this->probeWith("ISP_ROUTE:1\nISP_PPP:1\n" . <<<'PING'
  SEQ HOST                                     SIZE TTL TIME       STATUS
    0 10.72.103.1                                84  64 83ms403us  TTL exceeded
    1 10.72.103.1                                84  64 130ms953us TTL exceeded
    sent=2 received=0 packet-loss=100%
PING)->probe('172.16.16.253');

        $this->assertSame(OverlayReachabilityProbe::STATE_FOREIGN_HOP, $probe['state']);
        $this->assertSame('10.72.103.1', $probe['hop']);
        $this->assertSame('ttl exceeded', $probe['detail']);
        $this->assertTrue($probe['in_overlay']);
    }

    public function test_silence_is_never_conclusive(): void
    {
        // El script de provisión de ISPWatch abre TCP 22/8291/8728 desde la red
        // de gestión pero NO abre ICMP: un cliente con drop por defecto en el
        // chain input no contesta ping y aun así se administra sin problema.
        // Cortar aquí dejaría sin lectura de WAN a routers que funcionan.
        $sonda = $this->probeWith("ISP_ROUTE:1\nISP_PPP:1\n" . <<<'PING'
  SEQ HOST                                     SIZE TTL TIME       STATUS
    0 172.16.16.253                                                timeout
    1 172.16.16.253                                                timeout
    sent=2 received=0 packet-loss=100%
PING);
        $probe = $sonda->probe('172.16.16.253');

        $this->assertSame(OverlayReachabilityProbe::STATE_SILENT, $probe['state']);
        $this->assertFalse($sonda->isConclusiveFailure($probe));
    }

    public function test_a_foreign_hop_outside_our_overlay_is_not_conclusive(): void
    {
        // Sin ruta /32 ni sesión VPN, el primer salto SIEMPRE es el gateway del
        // CORE: es lo que se ve al preguntar por una dirección que el overlay no
        // administra. Tratarlo como avería cortaría cualquier router alcanzable
        // por fuera del túnel.
        $sonda = $this->probeWith("ISP_ROUTE:0\nISP_PPP:0\n" . <<<'PING'
  SEQ HOST                                     SIZE TTL TIME       STATUS
    0 198.211.111.7                              84  64 1ms855us   TTL exceeded
    sent=2 received=0 packet-loss=100%
PING);
        $probe = $sonda->probe('172.16.16.99');

        $this->assertSame(OverlayReachabilityProbe::STATE_FOREIGN_HOP, $probe['state']);
        $this->assertFalse($probe['in_overlay']);
        $this->assertFalse($sonda->isConclusiveFailure($probe));
    }

    public function test_a_core_that_does_not_answer_leaves_the_verdict_open(): void
    {
        $sonda = $this->probeWithResult(['success' => false, 'message' => 'No se pudo conectar al MikroTik via SSH']);
        $probe = $sonda->probe('172.16.16.253');

        $this->assertSame(OverlayReachabilityProbe::STATE_UNKNOWN, $probe['state']);
        $this->assertFalse($sonda->isConclusiveFailure($probe));
    }

    public function test_it_refuses_to_build_a_command_with_a_value_that_is_not_an_ip(): void
    {
        $sent = [];
        $probe = $this->probeWithResult(['success' => true, 'output' => ''], $sent)
            ->probe('172.16.16.253; /system reboot');

        $this->assertSame(OverlayReachabilityProbe::STATE_UNKNOWN, $probe['state']);
        $this->assertSame([], $sent, 'una IP inválida no puede llegar a componer un comando de RouterOS');
    }

    public function test_the_explanation_names_the_hop_and_the_checks(): void
    {
        $explained = $this->probeWithResult(['success' => true, 'output' => ''])->explain(
            ['state' => OverlayReachabilityProbe::STATE_FOREIGN_HOP, 'hop' => '10.72.103.1', 'detail' => 'ttl exceeded', 'in_overlay' => true],
            '172.16.16.253'
        );

        $this->assertStringContainsString('10.72.103.1', $explained['message']);
        $this->assertStringContainsString('172.16.16.253', $explained['message']);
        // El operador tiene que salir de aquí sabiendo que NO es credenciales.
        $this->assertStringContainsString('no es un problema de credenciales', $explained['message']);
        $this->assertStringContainsString('/ip address print', $explained['hint']);
    }

    private function probeWith(string $output): OverlayReachabilityProbe
    {
        return $this->probeWithResult(['success' => true, 'output' => $output]);
    }

    private function probeWithResult(array $result, array &$sentCommands = []): OverlayReachabilityProbe
    {
        $manager = new class($result, $sentCommands) extends MikroTikConnectionManager
        {
            public function __construct(private array $result, private array &$sent)
            {
            }

            public function executeSsh(string $command, ?int $timeoutSeconds = null): array
            {
                $this->sent[] = $command;

                return $this->result;
            }
        };

        return new OverlayReachabilityProbe($manager);
    }
}
