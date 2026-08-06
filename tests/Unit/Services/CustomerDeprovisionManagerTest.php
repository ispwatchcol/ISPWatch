<?php

namespace Tests\Unit\Services;

use App\Services\MikroTik\CustomerDeprovisionManager;
use App\Services\MikroTik\MikroTikConnectionManager;
use Tests\TestCase;

class CustomerDeprovisionManagerTest extends TestCase
{
    /** Captura el comando que se habría mandado al CORE. */
    private function captureCommand(array $identity): ?string
    {
        $sent = null;

        $connection = \Mockery::mock(MikroTikConnectionManager::class);
        $connection->shouldReceive('executeSsh')
            ->andReturnUsing(function (string $command) use (&$sent) {
                $sent = $command;
                return ['success' => true, 'output' => ''];
            });

        (new CustomerDeprovisionManager($connection))
            ->purge('172.18.1.2', 'admin', 'secreta', $identity);

        return $sent;
    }

    /**
     * La regla más importante de esta clase: sin ninguna clave por la que
     * buscar, un `find` sin criterio borraría recursos de OTROS clientes.
     */
    public function test_it_sends_nothing_when_the_customer_has_no_network_identity(): void
    {
        $connection = \Mockery::mock(MikroTikConnectionManager::class);
        $connection->shouldReceive('executeSsh')->never();

        $result = (new CustomerDeprovisionManager($connection))->purge(
            '172.18.1.2',
            'admin',
            'secreta',
            ['ip' => null, 'pppoe_username' => '', 'hotspot_username' => null, 'mac_address' => null]
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['statements']);
    }

    public function test_it_removes_every_resource_the_provisioning_creates(): void
    {
        $command = $this->captureCommand([
            'ip'               => '10.20.30.40',
            'pppoe_username'   => 'juan.perez',
            'hotspot_username' => 'juanp',
            'mac_address'      => 'AA:BB:CC:DD:EE:FF',
        ]);

        // El comando viaja dentro de `command="..."` y va escapado; se compara
        // sobre la forma sin escapar para que la prueba lea como el script real.
        $script = stripslashes($command);

        $this->assertStringContainsString('/ppp secret remove [find name="juan.perez"]', $script);
        $this->assertStringContainsString('/queue simple remove [find target=10.20.30.40/32]', $script);
        $this->assertStringContainsString('/ip hotspot user remove [find name="juanp"]', $script);
        $this->assertStringContainsString('/ip dhcp-server lease remove [find mac-address=AA:BB:CC:DD:EE:FF]', $script);
        $this->assertStringContainsString('/ip firewall address-list remove [find address=10.20.30.40]', $script);
        $this->assertStringContainsString('/ip arp remove [find address=10.20.30.40]', $script);
        $this->assertStringContainsString('ISPWatch-amarre-10.20.30.40', $script);
    }

    /**
     * Sin cortar la sesión viva el cliente sigue navegando hasta que el enlace
     * se caiga solo, que puede ser horas.
     */
    public function test_it_kills_the_live_session_before_removing_the_secret(): void
    {
        $script = stripslashes($this->captureCommand([
            'ip' => null, 'pppoe_username' => 'juan.perez',
            'hotspot_username' => null, 'mac_address' => null,
        ]));

        $active = strpos($script, '/ppp active remove');
        $secret = strpos($script, '/ppp secret remove');

        $this->assertNotFalse($active);
        $this->assertLessThan($secret, $active);
    }

    /**
     * En RouterOS un `remove [find ...]` sin coincidencias es un ERROR que
     * aborta el script: sin envolver cada sentencia, el primer recurso ausente
     * impediría borrar todos los demás.
     */
    public function test_every_statement_is_wrapped_so_a_missing_resource_does_not_abort_the_sweep(): void
    {
        $script = stripslashes($this->captureCommand([
            'ip' => '10.20.30.40', 'pppoe_username' => 'juan.perez',
            'hotspot_username' => null, 'mac_address' => null,
        ]));

        $statements = substr_count($script, ':do {');
        $this->assertGreaterThan(1, $statements);
        $this->assertSame($statements, substr_count($script, 'on-error={}'));
    }

    /**
     * SECURITY (OWASP A03): la IP y la MAC se interpolan SIN comillas en el
     * script; un valor con formato inválido no puede llegar al router.
     */
    public function test_a_malformed_ip_or_mac_is_dropped_instead_of_interpolated(): void
    {
        $script = stripslashes($this->captureCommand([
            'ip'               => '10.0.0.1; /system reboot',
            'mac_address'      => 'AA:BB; /user add',
            'pppoe_username'   => 'juan.perez',
            'hotspot_username' => null,
        ]));

        $this->assertStringNotContainsString('/system reboot', $script);
        $this->assertStringNotContainsString('/user add', $script);
        $this->assertStringNotContainsString('/ip arp remove', $script);
        $this->assertStringContainsString('/ppp secret remove', $script);
    }

    /**
     * El payload SÍ aparece en el texto del script — lo que no puede aparecer
     * es su comilla **sin escapar**, que es lo que cerraría la cadena antes de
     * tiempo y convertiría el resto en comandos. Por eso se comprueba la forma
     * exacta de la comilla y no la ausencia del texto.
     */
    public function test_quotes_in_a_username_cannot_break_out_of_the_routeros_string(): void
    {
        $script = stripslashes($this->captureCommand([
            'ip' => null, 'mac_address' => null, 'hotspot_username' => null,
            'pppoe_username' => 'juan"] ; /user add name=hacker ; [find name="x',
        ]));

        // Escapada: la cadena sigue abierta y todo el payload es un nombre.
        $this->assertStringContainsString('[find name="juan\\"]', $script);
        // Sin escapar sería una fuga: cerraría el find y ejecutaría lo de atrás.
        $this->assertStringNotContainsString('[find name="juan"]', $script);
    }
}
