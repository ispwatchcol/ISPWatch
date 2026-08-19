<?php

namespace Tests\Feature\Router;

use App\Models\Router;
use App\Services\VpnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El script L2TP no puede colgar el túnel del perfil PPP "default".
 *
 * Por qué esto merece una prueba propia: el fallo que provocó no se parece a un
 * fallo. En un router que además es servidor PPPoE —o sea, casi todos los CORE
 * de cliente— el perfil "default" trae `local-address` puesta, la IP con la que
 * atiende a sus abonados. El `l2tp-client` que use ese perfil se queda con ESA
 * dirección e ignora la que el CORE le asigna, así que el túnel figura conectado
 * en ambas puntas mientras el router descarta todo lo que le mandamos.
 *
 * Medido en CORE_SAN_ISIDRO (hEX, RouterOS 6.47.10) el 2026-08-18: el equipo
 * quedó con 10.72.103.1 en la interfaz del túnel en vez de 172.16.16.253, y
 * ISPWatch perdió el router entero —API, SSH, colas y cortes— sin un solo error
 * que dijera por qué. Ver § 40 de BITACORA_TECNICA.
 *
 * WireGuard no comparte el riesgo: su script FIJA la dirección con
 * `/ip address add`. Sólo L2TP la negocia, y sólo ahí puede pisarla un perfil.
 */
class VpnScriptTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function el_tunel_l2tp_no_usa_el_perfil_default(): void
    {
        $script = $this->l2tpScriptFor($this->router());

        $this->assertStringNotContainsString(
            'profile=default',
            $script,
            'el l2tp-client heredaría local-address del perfil PPPoE del cliente'
        );
        $this->assertStringContainsString('profile=ISPWatch-VPN', $script);
    }

    #[Test]
    public function el_script_crea_su_propio_perfil_sin_direcciones(): void
    {
        $script = $this->l2tpScriptFor($this->router());

        $this->assertStringContainsString('/ppp profile add name="ISPWatch-VPN"', $script);
        // Un local-address/remote-address en NUESTRO perfil reintroduciría el
        // mismo fallo por la puerta de atrás.
        $this->assertMatchesRegularExpression(
            '/\/ppp profile add name="ISPWatch-VPN"(?!.*(local|remote)-address).*$/m',
            $script
        );
    }

    #[Test]
    public function el_perfil_se_recrea_despues_de_quitar_la_interfaz(): void
    {
        // El orden no es estético: mientras el l2tp-client exista, el perfil
        // está en uso y RouterOS rechaza el remove. Al revés, el script deja el
        // perfil viejo —con la local-address que se quería quitar— en su sitio.
        $script = $this->l2tpScriptFor($this->router());

        $posInterfaz = strpos($script, '/interface l2tp-client remove');
        $posPerfil   = strpos($script, '/ppp profile remove');
        $posAlta     = strpos($script, 'add name="ISPWatch-VPN-CORE"');

        $this->assertNotFalse($posInterfaz);
        $this->assertNotFalse($posPerfil);
        $this->assertLessThan($posPerfil, $posInterfaz, 'el perfil se borra con la interfaz todavía puesta');
        $this->assertLessThan($posAlta, $posPerfil, 'la interfaz se crea antes de existir su perfil');
    }

    private function router(): Router
    {
        // Sin tenant a propósito: con tenant, generateL2tpScript sincroniza
        // pool y perfil contra el CORE real. Aquí se mide el TEXTO del script.
        return Router::create([
            'name'        => 'CORE de prueba',
            'tenant_id'   => null,
            'status'      => 'active',
            'ip'          => '172.16.99.2',
            'user_rb'     => 'ispwatch',
            'password_rb' => 'secreta',
            'firmware_version' => 'v6',
        ]);
    }

    private function l2tpScriptFor(Router $router): string
    {
        $service = new class extends VpnService
        {
            protected function syncPppSecret(string $username, string $password, string $routerName = '', string $profile = 'profile-vpn'): bool
            {
                return true; // el alta del secret en el CORE no es lo que se prueba aquí
            }
        };

        return $service->generateL2tpScript($router);
    }
}
