<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-110 · El pago no puede cruzar de operador.
 *
 * EL AGUJERO QUE CIERRA
 *
 * `POST /billing/payments` construía su payload con `$request->all()`, así que
 * el `tenant_id` que mandara el cliente llegaba tal cual hasta
 * `Payment::create()`. El hook `creating` de `BelongsToTenant` no lo corregía:
 * sólo rellena cuando viene vacío, y aquí venía lleno.
 *
 * Sumado a que `customer_id` se validaba con `exists:users,id` **sin acotar por
 * tenant**, un usuario con `view_billing` podía registrar un pago entero en otro
 * ISP, a nombre de un cliente ajeno, cambiando dos campos del cuerpo.
 *
 * Detectado como hallazgo fuera de alcance al cerrar KAN-109, que blindó la
 * forma de pago contra ese mismo cruce pero no el tenant del pago.
 *
 * LO QUE ESTOS TESTS FIJAN
 *
 * Que el tenant salga de la SESIÓN y no del cuerpo; que un cliente ajeno se
 * rechace; y —igual de importante— que las peticiones legítimas no cambien de
 * comportamiento, porque `RegisterPayment.vue` sí manda `tenant_id` y lo toma de
 * la sesión.
 */
class PaymentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otroTenant;
    private User $staff;
    private User $cliente;
    private User $clienteAjeno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant     = Tenant::factory()->create(['name' => 'ISP propio']);
        $this->otroTenant = Tenant::factory()->create(['name' => 'ISP ajeno']);

        $rol = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $rol->id,
        ]);

        $this->cliente      = $this->clienteDe($this->tenant);
        $this->clienteAjeno = $this->clienteDe($this->otroTenant);
    }

    private function clienteDe(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'   => $user->id,
            'name'      => 'Cliente',
            'last_name' => $tenant->name,
            'status'    => true,
        ]);

        return $user;
    }

    /** El cuerpo que manda hoy `RegisterPayment.vue`, con `tenant_id` incluido. */
    private function registrar(array $extra = [])
    {
        return $this->actingAs($this->staff)->postJson('/api/billing/payments', $extra + [
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'amount'       => 25000,
            'payment_date' => '2026-09-29',
            'method'       => 'Efectivo',
        ]);
    }

    // ── El tenant sale de la sesión ──────────────────────────────────────

    #[Test]
    public function un_tenant_id_ajeno_en_el_cuerpo_no_crea_el_pago_en_el_otro_isp(): void
    {
        // El cliente SÍ es del tenant propio: se aísla la vía del `tenant_id`
        // de la vía del `customer_id`, para saber cuál de las dos falló.
        $respuesta = $this->registrar(['tenant_id' => $this->otroTenant->id]);

        $respuesta->assertCreated();

        $this->assertSame(
            0,
            Payment::withoutGlobalScopes()->where('tenant_id', $this->otroTenant->id)->count(),
            'No puede quedar ningún pago en el ISP ajeno.',
        );

        $this->assertSame(
            $this->tenant->id,
            (int) Payment::withoutGlobalScopes()->sole()->tenant_id,
            'El pago queda en el tenant de la sesión, que es el único que manda.',
        );
    }

    #[Test]
    public function sin_tenant_id_en_el_cuerpo_el_pago_se_sella_igual(): void
    {
        $respuesta = $this->actingAs($this->staff)->postJson('/api/billing/payments', [
            'customer_id'  => $this->cliente->id,
            'amount'       => 12000,
            'payment_date' => '2026-09-29',
            'method'       => 'Efectivo',
        ]);

        $respuesta->assertCreated();

        $this->assertSame(
            $this->tenant->id,
            (int) Payment::withoutGlobalScopes()->sole()->tenant_id,
        );
    }

    // ── El cliente tiene que ser del mismo operador ──────────────────────

    #[Test]
    public function un_cliente_de_otro_isp_se_rechaza_con_422(): void
    {
        $respuesta = $this->registrar(['customer_id' => $this->clienteAjeno->id]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $this->assertSame(
            'El cliente no pertenece a este operador.',
            $respuesta->json('errors.customer_id.0'),
        );

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    #[Test]
    public function el_cruce_completo_tenant_mas_cliente_ajenos_tampoco_pasa(): void
    {
        // La petición que de verdad haría alguien que quisiera cruzar de ISP.
        $respuesta = $this->registrar([
            'tenant_id'   => $this->otroTenant->id,
            'customer_id' => $this->clienteAjeno->id,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    #[Test]
    public function un_customer_id_inexistente_sigue_rechazandose(): void
    {
        $respuesta = $this->registrar(['customer_id' => 999999]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    // ── El contrato de las peticiones legítimas no cambia ────────────────

    #[Test]
    public function un_pago_legitimo_se_registra_igual_que_antes(): void
    {
        $respuesta = $this->registrar();

        $respuesta->assertCreated();

        // La forma de la respuesta es la que ya consumía el frontend.
        $respuesta->assertJsonStructure(['id', 'tenant_id', 'customer_id', 'amount', 'allocations', 'reactivation', 'correlation_id']);

        $pago = Payment::withoutGlobalScopes()->sole();

        $this->assertSame($this->tenant->id, (int) $pago->tenant_id);
        $this->assertSame($this->cliente->id, (int) $pago->customer_id);
        $this->assertSame('25000.00', (string) $pago->amount);
        $this->assertSame($this->staff->id, (int) $pago->created_by);
    }

    #[Test]
    public function la_forma_de_pago_por_id_sigue_funcionando(): void
    {
        // No regresión de KAN-109: el enlace por id no se rompe con este cambio.
        $metodo = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Nequi',
            'is_active' => true,
        ]);

        $this->registrar(['payment_method_id' => $metodo->id, 'method' => null])
            ->assertCreated();

        $this->assertSame(
            $metodo->id,
            (int) Payment::withoutGlobalScopes()->sole()->payment_method_id,
        );
    }

    #[Test]
    public function una_forma_de_pago_de_otro_isp_sigue_rechazandose(): void
    {
        // KAN-109 dejó esta prueba; se repite aquí porque el sellado del tenant
        // toca justo el dato del que dependía aquella defensa.
        $ajena = PaymentMethod::create([
            'tenant_id' => $this->otroTenant->id,
            'name'      => 'Método ajeno',
            'is_active' => true,
        ]);

        $this->registrar(['payment_method_id' => $ajena->id, 'method' => null])
            ->assertStatus(422);

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    // ── Defensa en el servicio ───────────────────────────────────────────

    #[Test]
    public function el_servicio_rechaza_un_cliente_que_no_es_del_tenant(): void
    {
        // El controlador ya lo impide, pero el servicio es quien escribe la
        // fila: si mañana aparece un segundo llamador, el agujero no debe
        // reabrirse en silencio.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El cliente no pertenece a este operador.');

        app(BillingService::class)->registerPayment([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->clienteAjeno->id,
            'amount'       => 10000,
            'payment_date' => '2026-09-29',
            'method'       => 'Efectivo',
        ]);
    }

    #[Test]
    public function el_servicio_rechaza_un_pago_sin_operador(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(BillingService::class)->registerPayment([
            'customer_id'  => $this->cliente->id,
            'amount'       => 10000,
            'payment_date' => '2026-09-29',
            'method'       => 'Efectivo',
        ]);
    }

    // ── Edición y borrado: la protección que YA existía ──────────────────

    #[Test]
    public function un_pago_de_otro_isp_no_se_puede_editar(): void
    {
        $ajeno = $this->pagoAjeno();

        $this->actingAs($this->staff)
            ->putJson("/api/billing/payments/{$ajeno->id}", ['amount' => 1])
            ->assertNotFound();

        $this->assertSame(
            '50000.00',
            (string) Payment::withoutGlobalScopes()->find($ajeno->id)->amount,
        );
    }

    #[Test]
    public function un_pago_de_otro_isp_no_se_puede_eliminar(): void
    {
        $ajeno = $this->pagoAjeno();

        $this->actingAs($this->staff)
            ->deleteJson("/api/billing/payments/{$ajeno->id}")
            ->assertNotFound();

        $this->assertNotNull(Payment::withoutGlobalScopes()->find($ajeno->id));
    }

    #[Test]
    public function el_listado_no_muestra_pagos_de_otro_isp(): void
    {
        $this->pagoAjeno();
        $this->registrar()->assertCreated();

        $filas = $this->actingAs($this->staff)
            ->getJson('/api/billing/payments')->assertOk()->json('data');

        $this->assertCount(1, $filas);
        $this->assertSame($this->cliente->id, (int) $filas[0]['customer_id']);
    }

    /** Un pago del otro ISP, escrito por SQL directo para no pasar por la API. */
    private function pagoAjeno(): Payment
    {
        $id = DB::table('payments')->insertGetId([
            'tenant_id'    => $this->otroTenant->id,
            'customer_id'  => $this->clienteAjeno->id,
            'amount'       => 50000,
            'payment_date' => '2026-09-01',
            'method'       => 'Efectivo',
            'status'       => 'completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return Payment::withoutGlobalScopes()->findOrFail($id);
    }
}
