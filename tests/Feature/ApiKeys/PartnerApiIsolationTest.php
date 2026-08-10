<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiClient;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La prueba que de verdad importa de la API pública: que la llave de un tenant
 * NO pueda ver los datos de otro.
 *
 * El riesgo es concreto y está en el esquema: `customer_profile` no tiene
 * `tenant_id` propio — su frontera es el join con `users`. Un endpoint que se
 * olvide de ese join devuelve la base de clientes COMPLETA de la plataforma sin
 * dar ningún error. Por eso cada listado se prueba con dos tenants poblados y
 * se afirma que sólo sale el propio, no que "salga algo".
 */
class PartnerApiIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private ApiClient $clientA;
    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $this->clientA = ApiClient::create([
            'tenant_id' => $this->tenantA->id,
            'name'      => 'CRM del ISP A',
            'is_active' => true,
        ]);

        $this->tokenA = $this->issueKey($this->clientA, [
            'read:customers', 'read:billing', 'read:support',
        ]);

        $this->seedCustomer($this->tenantA, 'Ana', 'Tenant A');
        $this->seedCustomer($this->tenantB, 'Beto', 'Tenant B');
    }

    /**
     * Emite una llave real. No se usa Sanctum::actingAs a propósito: ese helper
     * inyecta un TransientToken, que EnsureApiKeyRequest rechaza — y con razón,
     * porque devuelve true en cualquier tokenCan() y anularía las abilities.
     * Probar con un token falso no probaría nada de la cadena real.
     */
    private function issueKey(ApiClient $client, array $abilities, array $ips = ['127.0.0.1']): string
    {
        $token = $client->createToken('test', $abilities);
        $token->accessToken->forceFill(['allowed_ips' => $ips])->save();

        return $token->plainTextToken;
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->tokenA)];
    }

    /** Crea un cliente completo (ficha + factura + pago + ticket + instalación). */
    private function seedCustomer(Tenant $tenant, string $name, string $marker): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'user_name' => $name,
        ]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => $name,
            'last_name'      => $marker,
            'address'        => $marker . ' 123',
            'service_status' => 'activo',
            'status'         => true,
            'pppoe_username' => strtolower($name) . '.pppoe',
            'pppoe_password' => 'SECRETO-' . $marker,
        ]);

        Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'number'       => 'INV-' . $marker . '-' . $user->id,
            'issue_date'   => '2026-08-01',
            'due_date'     => '2026-08-10',
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-31',
            'subtotal'     => 50000,
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ]);

        Payment::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 50000,
            'payment_date' => '2026-08-05',
            'method'       => 'efectivo',
            'reference'    => 'PAY-' . $marker,
            'status'       => 'completed',
        ]);

        SupportTicket::create([
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
            'subject'   => 'Sin señal ' . $marker,
            'status'    => 'open',
            'priority'  => 'high',
        ]);

        CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $user->id,
            'scheduled_date' => '2026-08-02',
            'address'        => $marker . ' 123',
            'status'         => 'pending',
        ]);

        return $user;
    }

    #[Test]
    public function la_llave_solo_ve_los_clientes_de_su_tenant(): void
    {
        $response = $this->getJson('/api/v1/partner/customers', $this->headers());

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Tenant A', $response->json('data.0.last_name'));
        $response->assertJsonMissing(['last_name' => 'Tenant B']);
    }

    #[Test]
    public function la_llave_solo_ve_las_facturas_y_pagos_de_su_tenant(): void
    {
        $invoices = $this->getJson('/api/v1/partner/invoices', $this->headers())->assertOk();
        $this->assertCount(1, $invoices->json('data'));
        $this->assertStringContainsString('Tenant A', $invoices->json('data.0.number'));

        $payments = $this->getJson('/api/v1/partner/payments', $this->headers())->assertOk();
        $this->assertCount(1, $payments->json('data'));
        $this->assertStringContainsString('Tenant A', $payments->json('data.0.reference'));
    }

    #[Test]
    public function la_llave_solo_ve_los_tickets_e_instalaciones_de_su_tenant(): void
    {
        $tickets = $this->getJson('/api/v1/partner/tickets', $this->headers())->assertOk();
        $this->assertCount(1, $tickets->json('data'));
        $this->assertStringContainsString('Tenant A', $tickets->json('data.0.subject'));

        $installs = $this->getJson('/api/v1/partner/installations', $this->headers())->assertOk();
        $this->assertCount(1, $installs->json('data'));
        $this->assertStringContainsString('Tenant A', $installs->json('data.0.address'));
    }

    #[Test]
    public function pedir_un_cliente_de_otro_tenant_devuelve_404_y_no_403(): void
    {
        $otherCustomer = CustomerProfile::query()
            ->join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $this->tenantB->id)
            ->value('customer_profile.user_id');

        // 404 y no 403: un 403 le confirmaría al integrador que ese id existe
        // en la plataforma, que es justo lo que no debe poder averiguar.
        $this->getJson("/api/v1/partner/customers/{$otherCustomer}", $this->headers())
            ->assertNotFound();
    }

    #[Test]
    public function la_respuesta_nunca_incluye_credenciales_de_red(): void
    {
        $response = $this->getJson('/api/v1/partner/customers', $this->headers());

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('SECRETO-', $body, 'La contraseña PPPoE salió en la respuesta.');
        $this->assertStringNotContainsString('pppoe_password', $body);
        $this->assertStringNotContainsString('hotspot_password', $body);
    }
}
