<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiClient;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El allowlist de la API partner tiene que evaluar la IP real del cliente,
 * no la del borde de Cloudflare.
 *
 * Contexto: el 2026-08-21 se emitió la primera llave real para un integrador
 * (Colombia Net de Occidente) y su primera llamada real quedó rechazada con
 * `ip_not_allowed` pese a llamar desde la IP correcta. `api_key_request_logs`
 * tenía CERO peticiones antes de esa prueba: el allowlist nunca se había
 * validado contra tráfico externo de verdad, sólo contra la suite, que fija
 * `allowed_ips = ['127.0.0.1']` sin pasar por ningún proxy.
 *
 * La causa: el sitio está detrás de Cloudflare, y `$request->ip()` —incluso
 * con `trustProxies(at: '*')`— resuelve al borde de Cloudflare
 * (`104.22.86.188` en el incidente real), porque DigitalOcean App Platform no
 * conserva la cadena `X-Forwarded-For` que Cloudflare arma. Ver
 * `RequestMacrosServiceProvider` para el porqué completo y el riesgo que
 * queda abierto a propósito (P-38 en MEJORAS_RECOMENDADAS.md).
 *
 * Esta prueba reproduce el incidente: simula exactamente la cabecera que
 * Cloudflare agrega en producción y comprueba que el middleware —y el propio
 * `/ping`, el endpoint pensado para que el integrador se autodiagnostique—
 * usan la IP real y no la del borde.
 */
class PartnerApiCloudflareIpTest extends TestCase
{
    use RefreshDatabase;

    private function issueKeyAllowingOnly(string $ip): string
    {
        $tenant = Tenant::factory()->create();

        $client = ApiClient::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Colombia Net de Occidente',
            'is_active' => true,
        ]);

        $token = $client->createToken('cno-v1b-readonly', ['read:customers']);
        $token->accessToken->forceFill(['allowed_ips' => [$ip]])->save();

        return $token->plainTextToken;
    }

    #[Test]
    public function acepta_la_llamada_cuando_la_ip_del_cliente_viaja_en_cf_connecting_ip(): void
    {
        $token = $this->issueKeyAllowingOnly('38.19.43.29');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '104.22.86.188'])
            ->withHeaders([
                'Authorization'    => 'Bearer ' . $token,
                'Accept'           => 'application/json',
                'CF-Connecting-IP' => '38.19.43.29',
            ])
            ->getJson('/api/v1/partner/ping');

        $response->assertOk();
        $response->assertJsonPath('data.your_ip', '38.19.43.29');
    }

    #[Test]
    public function rechaza_la_llamada_cuando_la_ip_real_no_esta_en_el_allowlist(): void
    {
        // El caso simétrico: si la IP real (no la del borde) no está
        // autorizada, sigue rechazando. La macro no debilita el control.
        $token = $this->issueKeyAllowingOnly('38.19.43.29');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '104.22.86.188'])
            ->withHeaders([
                'Authorization'    => 'Bearer ' . $token,
                'Accept'           => 'application/json',
                'CF-Connecting-IP' => '190.14.255.110',
            ])
            ->getJson('/api/v1/partner/ping');

        $response->assertForbidden();
        $response->assertJsonPath('error', 'ip_not_allowed');
    }

    #[Test]
    public function ping_devuelve_your_ip_real_y_no_el_borde_de_cloudflare(): void
    {
        // Regresión concreta: `your_ip` es el dato que el integrador copia a
        // su allowlist. Si dijera la IP de Cloudflare, el integrador
        // autorizaría exactamente la IP equivocada.
        $token = $this->issueKeyAllowingOnly('190.14.255.110');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '104.22.86.188'])
            ->withHeaders([
                'Authorization'    => 'Bearer ' . $token,
                'Accept'           => 'application/json',
                'CF-Connecting-IP' => '190.14.255.110',
            ])
            ->getJson('/api/v1/partner/ping');

        $response->assertOk();
        $this->assertNotSame('104.22.86.188', $response->json('data.your_ip'));
        $response->assertJsonPath('data.your_ip', '190.14.255.110');
    }

    #[Test]
    public function sin_cabecera_de_cloudflare_sigue_funcionando_como_antes(): void
    {
        // Local, pruebas, o cualquier despliegue sin Cloudflare delante: no
        // debe romperse por la ausencia de la cabecera.
        $token = $this->issueKeyAllowingOnly('127.0.0.1');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
            ->getJson('/api/v1/partner/ping');

        $response->assertOk();
        $response->assertJsonPath('data.your_ip', '127.0.0.1');
    }
}
