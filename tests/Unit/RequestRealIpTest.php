<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `realIp()` es lo único que se interpone entre el allowlist de la API partner
 * y el borde de Cloudflare (ver `RequestMacrosServiceProvider` para el porqué
 * completo: el 2026-08-21 la primera llamada real a la API en producción
 * quedó registrada con la IP de Cloudflare, no la del ISP que llamaba).
 *
 * Estas pruebas fijan el contrato de la macro; no prueban Cloudflare en sí
 * —eso sólo se verifica contra tráfico real—.
 */
class RequestRealIpTest extends TestCase
{
    #[Test]
    public function usa_cf_connecting_ip_cuando_esta_presente_y_es_valida(): void
    {
        $request = Request::create('/cualquiera', 'GET', server: [
            'REMOTE_ADDR' => '104.22.86.188', // borde de Cloudflare
        ]);
        $request->headers->set('CF-Connecting-IP', '190.14.255.110');

        $this->assertSame('190.14.255.110', $request->realIp());
    }

    #[Test]
    public function cae_a_ip_normal_sin_la_cabecera(): void
    {
        // El caso local, el de pruebas, y cualquier despliegue sin Cloudflare
        // delante: no hay cabecera que leer, y realIp() no puede inventar nada.
        $request = Request::create('/cualquiera', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $this->assertSame('127.0.0.1', $request->realIp());
    }

    #[Test]
    public function ignora_una_cabecera_que_no_es_una_ip_valida(): void
    {
        // Fallar cerrado hacia el valor de siempre, no hacia un dato basura:
        // un valor no parseable en la cabecera no debe filtrarse a la
        // allowlist, al rate limiter ni a la auditoría.
        $request = Request::create('/cualquiera', 'GET', server: [
            'REMOTE_ADDR' => '104.22.86.188',
        ]);
        $request->headers->set('CF-Connecting-IP', 'no-es-una-ip');

        $this->assertSame('104.22.86.188', $request->realIp());
    }

    #[Test]
    public function ignora_una_cabecera_vacia(): void
    {
        $request = Request::create('/cualquiera', 'GET', server: [
            'REMOTE_ADDR' => '104.22.86.188',
        ]);
        $request->headers->set('CF-Connecting-IP', '');

        $this->assertSame('104.22.86.188', $request->realIp());
    }

    #[Test]
    public function acepta_ipv6_en_la_cabecera(): void
    {
        $request = Request::create('/cualquiera', 'GET', server: [
            'REMOTE_ADDR' => '104.22.86.188',
        ]);
        $request->headers->set('CF-Connecting-IP', '2001:db8::1');

        $this->assertSame('2001:db8::1', $request->realIp());
    }
}
