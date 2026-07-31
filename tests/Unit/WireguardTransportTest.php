<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\MikroTik\WireguardManager;
use PHPUnit\Framework\TestCase;

/**
 * Cubre las decisiones del transporte dual WireGuard/L2TP.
 *
 * El caso real que las motiva (2026-07-30): CORE_TOCAIMA estuvo 8 días caído
 * porque su IKE salía por una IP pública y su L2TP por otra. WireGuard elimina
 * esa clase de falla, pero NO se puede aplicar a todos: RouterOS v6 no lo tiene.
 * De ahí que la elección sea por router y que equivocarse tenga costo — emitir
 * un script WireGuard a un v6 lo deja sin túnel.
 */
class WireguardTransportTest extends TestCase
{
    /** @dataProvider firmwareVersions */
    public function test_firmware_support_detection(?string $version, bool $expected): void
    {
        $this->assertSame(
            $expected,
            Router::firmwareSupportsWireguard($version),
            "Versión evaluada: " . var_export($version, true)
        );
    }

    public static function firmwareVersions(): array
    {
        return [
            'v7 estable con sufijo'  => ['7.23.1 (stable)', true],
            'v7 simple'              => ['7.1', true],
            'v7 release candidate'   => ['7.1rc4', true],
            'v7 mayor futuro'        => ['8.0.1', true],
            'v7.0 no alcanza'        => ['7.0.5', false],
            'v6 tardío'              => ['6.49.10', false],
            'v6 antiguo'             => ['6.40', false],
            // Ante lo ilegible elegimos L2TP: funciona en las dos ramas, así que
            // el fallback seguro es el transporte viejo, nunca el nuevo.
            'vacío'                  => ['', false],
            'null'                   => [null, false],
            'basura'                 => ['desconocido', false],
        ];
    }

    public function test_transport_defaults_to_l2tp(): void
    {
        $router = new Router();

        $this->assertSame(Router::TRANSPORT_L2TP, $router->vpnTransport());
        $this->assertFalse($router->usesWireguard());
    }

    public function test_transport_reads_wireguard_when_set(): void
    {
        $router = new Router();
        $router->vpn_transport = Router::TRANSPORT_WIREGUARD;

        $this->assertTrue($router->usesWireguard());
    }

    public function test_unknown_transport_value_falls_back_to_l2tp(): void
    {
        $router = new Router();
        $router->vpn_transport = 'openvpn';

        $this->assertSame(Router::TRANSPORT_L2TP, $router->vpnTransport());
        $this->assertFalse($router->usesWireguard());
    }

    public function test_keypair_is_valid_x25519(): void
    {
        $manager = new WireguardManager();
        $pair    = $manager->generateKeypair();

        // RouterOS espera claves X25519 en base64: 32 bytes crudos → 44 chars.
        foreach (['private', 'public'] as $which) {
            $raw = base64_decode($pair[$which], true);
            $this->assertNotFalse($raw, "La clave {$which} no es base64 válido");
            $this->assertSame(32, strlen($raw), "La clave {$which} no mide 32 bytes");
        }

        $this->assertNotSame($pair['private'], $pair['public']);
    }

    public function test_keypairs_are_unique_per_call(): void
    {
        $manager = new WireguardManager();

        $this->assertNotSame(
            $manager->generateKeypair()['private'],
            $manager->generateKeypair()['private']
        );
    }

    public function test_tenant_subnet_formula(): void
    {
        $manager = new WireguardManager();

        $this->assertSame([
            'local_address' => '172.18.16.1',
            'client_start'  => '172.18.16.2',
            'network_cidr'  => '172.18.16.0/24',
        ], $manager->tenantSubnet(16));

        $this->assertSame('172.18.17.1', $manager->tenantSubnet(17)['local_address']);
        $this->assertSame('172.18.1.1', $manager->tenantSubnet(1)['local_address']);
    }

    /**
     * WireGuard arranca en 172.18 y L2TP en 172.16 justamente para no pisarse.
     * Si alguien "simplifica" la fórmula y las junta, los dos transportes se
     * repartirían el mismo /24 y el CORE tendría la misma IP en dos interfaces.
     */
    public function test_wireguard_base_does_not_overlap_l2tp_base(): void
    {
        $manager = new WireguardManager();

        foreach ([1, 16, 17, 254] as $tenantId) {
            $second = (int) explode('.', $manager->tenantSubnet($tenantId)['local_address'])[1];

            $this->assertGreaterThanOrEqual(
                18,
                $second,
                "El tenant {$tenantId} cae en el rango que usa L2TP (172.16/172.17)"
            );
        }
    }

    public function test_tenant_subnet_rolls_over_past_254(): void
    {
        $manager = new WireguardManager();

        $this->assertSame('172.19.1.1', $manager->tenantSubnet(255)['local_address']);
    }

    public function test_peer_comment_identifies_router_uniquely(): void
    {
        $manager = new WireguardManager();

        $router = new Router();
        $router->id   = 45;
        $router->name = 'CORE_TOCAIMA';

        // El comentario es la clave con la que se hace upsert del peer: si dos
        // routers colisionaran, provisionar uno borraría el peer del otro.
        $this->assertSame('ISPWatch - CORE_TOCAIMA (router 45)', $manager->peerComment($router));
    }
}
