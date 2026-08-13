<?php

namespace Tests\Unit;

use App\Services\MikroTik\PppSecretManager;
use PHPUnit\Framework\TestCase;

/**
 * Dos túneles L2TP discando desde la MISMA IP pública no son redundancia: se
 * reciclan entre sí y dejan al router con dos direcciones de overlay, así que
 * todo lo que el CORE inicia hacia él muere a mitad de camino.
 *
 * Medido en producción el 2026-08-13 sobre CORE_SAN_ISIDRO: sus dos sesiones
 * (`6hRZFLsOnM` y el huérfano `SV5YANDeKg`) entraban desde 190.14.255.100 y
 * reciclaban cada 1-2 minutos, mientras la sesión que no compartía pública
 * llevaba 1h44m intacta. La lectura de interfaces WAN fallaba y el botón
 * "Verificar VPN" seguía diciendo "✅ VPN ACTIVA", sin nada que relacionara
 * ambas cosas.
 */
class DuplicateVpnTunnelDetectionTest extends TestCase
{
    /** Tabla /ppp active tal como la devolvió el CORE aquel día. */
    private function productionSessions(): array
    {
        return [
            ['name' => 'mL6b8SjaHa', 'address' => '172.16.16.254', 'caller_id' => '190.14.255.110', 'uptime' => '1h44m21s'],
            ['name' => '6hRZFLsOnM', 'address' => '172.16.17.248', 'caller_id' => '190.14.255.100', 'uptime' => '2m22s'],
            ['name' => 'SV5YANDeKg', 'address' => '172.16.17.249', 'caller_id' => '190.14.255.100', 'uptime' => '45s'],
        ];
    }

    private function managerReturning(array $sessions): PppSecretManager
    {
        return new class($sessions) extends PppSecretManager
        {
            public function __construct(private array $sessions)
            {
                // A propósito sin llamar al padre: no queremos abrir conexiones.
            }

            public function getPppActive(): array
            {
                return ['success' => true, 'method' => 'API', 'connections' => $this->sessions];
            }
        };
    }

    public function test_it_flags_a_session_sharing_its_public_ip_with_another(): void
    {
        $result = $this->managerReturning($this->productionSessions())->isVpnConnected('6hRZFLsOnM');

        $this->assertTrue($result['connected']);
        $this->assertSame('190.14.255.100', $result['caller_id']);
        $this->assertCount(1, $result['duplicate_tunnels']);
        $this->assertSame('SV5YANDeKg', $result['duplicate_tunnels'][0]['name']);
        $this->assertStringContainsString('duplicado', $result['message']);
    }

    public function test_a_session_alone_on_its_public_ip_stays_a_plain_ok(): void
    {
        // No basta con detectar el duplicado: el caso sano no puede ensuciarse
        // con advertencias, o el aviso deja de significar algo.
        $result = $this->managerReturning($this->productionSessions())->isVpnConnected('mL6b8SjaHa');

        $this->assertTrue($result['connected']);
        $this->assertSame([], $result['duplicate_tunnels']);
        $this->assertSame('✅ VPN ACTIVA', $result['message']);
    }

    public function test_it_does_not_invent_duplicates_when_the_caller_id_is_unknown(): void
    {
        // Sin caller-id no se puede comparar. Callar es correcto; afirmar que no
        // hay duplicados sería una respuesta inventada.
        $sessions = [
            ['name' => 'aaa', 'address' => '172.16.17.10', 'caller_id' => '', 'uptime' => '1m'],
            ['name' => 'bbb', 'address' => '172.16.17.11', 'caller_id' => '', 'uptime' => '1m'],
        ];

        $result = $this->managerReturning($sessions)->isVpnConnected('aaa');

        $this->assertSame([], $result['duplicate_tunnels']);
        $this->assertSame('✅ VPN ACTIVA', $result['message']);
    }

    public function test_a_router_with_no_session_is_still_reported_as_down(): void
    {
        $result = $this->managerReturning($this->productionSessions())->isVpnConnected('noExiste');

        $this->assertFalse($result['connected']);
        $this->assertStringContainsString('no conectada', $result['message']);
    }
}
