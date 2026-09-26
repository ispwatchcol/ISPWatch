<?php

namespace Tests\Feature\Billing;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InstallationBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-109: renombrar una forma de pago en el catálogo dejaba "sin forma de
 * pago" a los pagos registrados antes.
 *
 * `payments.method` era sólo el nombre copiado al registrar. El filtro de
 * recaudos comparaba contra el nombre vigente, así que todo lo cobrado antes
 * del renombrado desaparecía del filtro y del CSV, y el modal de edición abría
 * con el select vacío: elegir cualquier opción y guardar sobrescribía el dato
 * verdadero del pago.
 *
 * Lo que fijan estas pruebas:
 *  - la referencia estable es `payment_method_id`; `method` NUNCA se reescribe;
 *  - el relleno sólo enlaza coincidencias únicas dentro del mismo tenant;
 *  - un select vacío no borra la forma de pago de un pago;
 *  - revertir la migración no pierde nada.
 */
class PaymentMethodLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otroTenant;
    private User $staff;
    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant     = Tenant::factory()->create(['name' => 'Chaguaní']);
        $this->otroTenant = Tenant::factory()->create(['name' => 'Otro ISP']);

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->cliente = $this->customer($this->tenant);
    }

    // ── Ayudantes ────────────────────────────────────────────────────────

    private function customer(Tenant $tenant): User
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

    private function metodo(Tenant $tenant, string $name, bool $active = true): PaymentMethod
    {
        return PaymentMethod::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name'      => $name,
            'is_active' => $active,
        ]);
    }

    /** Pago "histórico": como quedaban antes de KAN-109, con texto y sin enlace. */
    private function pagoHistorico(Tenant $tenant, User $customer, string $method, array $extra = []): Payment
    {
        return Payment::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'amount'       => 30000,
            'payment_date' => '2026-08-10',
            'method'       => $method,
            'status'       => 'completed',
        ], $extra));
    }

    private function registrar(array $data)
    {
        return $this->postJson('/api/billing/payments', array_merge([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'amount'       => 25000,
            'payment_date' => '2026-09-01',
        ], $data));
    }

    private function migracion()
    {
        return require database_path('migrations/2026_09_26_000001_add_payment_method_id_to_payments_table.php');
    }

    private function fresco(Payment $payment): object
    {
        return DB::table('payments')->where('id', $payment->id)->first();
    }

    private function csv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines   = array_values(array_filter(explode("\n", trim($content)), fn ($l) => trim($l) !== ''));

        return array_map(fn ($line) => str_getcsv(rtrim($line, "\r"), ';', '"', ''), $lines);
    }

    // ── Renombrado: el caso que reportó el cliente ───────────────────────

    #[Test]
    public function renombrar_la_forma_de_pago_no_saca_los_pagos_anteriores_del_filtro_ni_del_csv(): void
    {
        Sanctum::actingAs($this->staff);
        $nequi = $this->metodo($this->tenant, 'Nequi');

        $antes = $this->registrar(['payment_method_id' => $nequi->id, 'reference' => 'ANTES'])->assertCreated();

        $this->putJson("/api/billing/payment-methods/{$nequi->id}", ['name' => 'Nequi Empresarial'])->assertOk();

        $despues = $this->registrar(['payment_method_id' => $nequi->id, 'reference' => 'DESPUES'])->assertCreated();

        // Cada pago conserva el nombre con que se registró…
        $this->assertSame('Nequi', $antes->json('method'));
        $this->assertSame('Nequi Empresarial', $despues->json('method'));

        // …y los dos cuelgan de la misma forma de pago.
        $response = $this->getJson("/api/billing/payments?payment_method_id={$nequi->id}")->assertOk();
        $this->assertEqualsCanonicalizing(['ANTES', 'DESPUES'], collect($response->json('data'))->pluck('reference')->all());
        $this->assertSame(2, $response->json('summary.count'));
        $this->assertSame(['Nequi Empresarial'], collect($response->json('data'))->pluck('payment_method.name')->unique()->values()->all());

        // El CSV cuadra con el filtro: mismo nombre vigente en las dos filas.
        $rows = $this->csv($this->get("/api/billing/payments/export?payment_method_id={$nequi->id}")->assertOk()->streamedContent());
        $this->assertCount(3, $rows, 'Cabecera + los dos pagos.');
        $this->assertSame(['Nequi Empresarial', 'Nequi Empresarial'], [$rows[1][3], $rows[2][3]]);
    }

    #[Test]
    public function el_filtro_por_texto_se_mantiene_por_compatibilidad(): void
    {
        Sanctum::actingAs($this->staff);
        $this->pagoHistorico($this->tenant, $this->cliente, 'Nequi viejo', ['reference' => 'VIEJO']);
        $this->pagoHistorico($this->tenant, $this->cliente, 'cash', ['reference' => 'CASH']);

        $this->getJson('/api/billing/payments?method=' . urlencode('Nequi viejo'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'VIEJO');
    }

    // ── Edición ──────────────────────────────────────────────────────────

    #[Test]
    public function editar_un_pago_historico_con_el_select_vacio_no_borra_su_forma_de_pago(): void
    {
        Sanctum::actingAs($this->staff);
        $this->metodo($this->tenant, 'Efectivo');
        $pago = $this->pagoHistorico($this->tenant, $this->cliente, 'Nequi viejo');

        // Lo que manda un select sin selección, en sus dos formas.
        $this->putJson("/api/billing/payments/{$pago->id}", [
            'amount'            => 35000,
            'payment_method_id' => null,
            'method'            => '',
        ])->assertOk();

        $fila = $this->fresco($pago);
        $this->assertSame('Nequi viejo', $fila->method);
        $this->assertNull($fila->payment_method_id);
        $this->assertEquals(35000, $fila->amount);
    }

    #[Test]
    public function editar_el_monto_de_un_pago_de_una_forma_renombrada_no_reescribe_su_nombre(): void
    {
        Sanctum::actingAs($this->staff);
        $nequi = $this->metodo($this->tenant, 'Nequi');
        $pago  = Payment::find($this->registrar(['payment_method_id' => $nequi->id])->assertCreated()->json('id'));

        $nequi->update(['name' => 'Nequi Empresarial']);

        // El front vuelve a mandar el mismo id que ya tenía.
        $this->putJson("/api/billing/payments/{$pago->id}", [
            'amount'            => 26000,
            'payment_method_id' => $nequi->id,
        ])->assertOk()->assertJsonPath('payment_method.name', 'Nequi Empresarial');

        $fila = $this->fresco($pago);
        $this->assertSame('Nequi', $fila->method, 'Corregir el monto no es motivo para reescribir con qué nombre se cobró.');
        $this->assertSame($nequi->id, (int) $fila->payment_method_id);
    }

    #[Test]
    public function cambiar_la_forma_de_pago_al_editar_cambia_id_y_texto_y_queda_auditado(): void
    {
        Sanctum::actingAs($this->staff);
        $efectivo = $this->metodo($this->tenant, 'Efectivo');
        $pago     = $this->pagoHistorico($this->tenant, $this->cliente, 'Nequi viejo');

        $this->putJson("/api/billing/payments/{$pago->id}", ['payment_method_id' => $efectivo->id])->assertOk();

        $fila = $this->fresco($pago);
        $this->assertSame('Efectivo', $fila->method);
        $this->assertSame($efectivo->id, (int) $fila->payment_method_id);

        $log = AuditLog::where('action', 'payment.updated')->where('model_id', $pago->id)->latest('id')->first();
        $this->assertNotNull($log, 'El cambio de forma de pago tiene que quedar en la bitácora.');
        $this->assertSame('Nequi viejo', $log->old_values['method']);
        $this->assertSame('Efectivo', $log->new_values['method']);
        $this->assertEquals($efectivo->id, $log->new_values['payment_method_id']);
    }

    #[Test]
    public function un_cliente_viejo_que_reenvia_el_mismo_texto_no_desenlaza_el_pago(): void
    {
        // Un bundle en caché del navegador todavía manda `method` con el texto
        // que traía el pago, aunque ese nombre ya no esté en el catálogo.
        Sanctum::actingAs($this->staff);
        $nequi = $this->metodo($this->tenant, 'Nequi');
        $pago  = Payment::find($this->registrar(['payment_method_id' => $nequi->id])->json('id'));
        $nequi->update(['name' => 'Nequi Empresarial']);

        $this->putJson("/api/billing/payments/{$pago->id}", ['amount' => 27000, 'method' => 'Nequi'])->assertOk();

        $this->assertSame($nequi->id, (int) $this->fresco($pago)->payment_method_id);
    }

    // ── Alta ─────────────────────────────────────────────────────────────

    #[Test]
    public function un_pago_nuevo_por_id_guarda_el_enlace_y_el_nombre_vigente(): void
    {
        Sanctum::actingAs($this->staff);
        $tarjeta = $this->metodo($this->tenant, 'Tarjeta');

        $this->registrar(['payment_method_id' => $tarjeta->id])
            ->assertCreated()
            ->assertJsonPath('method', 'Tarjeta')
            ->assertJsonPath('payment_method_id', $tarjeta->id);
    }

    #[Test]
    public function un_pago_nuevo_por_texto_se_enlaza_si_el_nombre_coincide_y_si_no_conserva_el_texto(): void
    {
        Sanctum::actingAs($this->staff);
        $efectivo = $this->metodo($this->tenant, 'Efectivo');

        $this->registrar(['method' => ' EFECTIVO '])
            ->assertCreated()
            ->assertJsonPath('payment_method_id', $efectivo->id);

        // `cash` es el valor por defecto de la API vieja: no se traduce a
        // "Efectivo" por intuición, se guarda tal cual y sin enlace.
        $this->registrar(['method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('method', 'cash')
            ->assertJsonPath('payment_method_id', null);
    }

    #[Test]
    public function registrar_sin_forma_de_pago_se_rechaza(): void
    {
        Sanctum::actingAs($this->staff);

        $this->registrar([])->assertStatus(422)->assertJsonValidationErrors('method');
    }

    // ── Nombres duplicados o ambiguos ────────────────────────────────────

    #[Test]
    public function con_nombres_duplicados_en_el_catalogo_el_texto_no_se_enlaza_a_ninguno(): void
    {
        Sanctum::actingAs($this->staff);
        // El auto-sembrado de index() no tiene candado: dos primeras visitas
        // simultáneas dejan el catálogo repetido.
        $uno = $this->metodo($this->tenant, 'Tarjeta');
        $this->metodo($this->tenant, 'tarjeta ');

        $this->registrar(['method' => 'Tarjeta'])
            ->assertCreated()
            ->assertJsonPath('payment_method_id', null)
            ->assertJsonPath('method', 'Tarjeta');

        // Por id no hay ambigüedad.
        $this->registrar(['payment_method_id' => $uno->id])->assertCreated()->assertJsonPath('payment_method_id', $uno->id);

        $historico = $this->pagoHistorico($this->tenant, $this->cliente, 'TARJETA');
        $this->artisan('payments:link-methods', ['--apply' => true])->assertSuccessful();
        $this->assertNull($this->fresco($historico)->payment_method_id, 'Un nombre ambiguo no se enlaza: sería adivinar.');
    }

    // ── Aislamiento entre tenants ────────────────────────────────────────

    #[Test]
    public function la_forma_de_pago_de_otro_tenant_se_rechaza_al_registrar_y_al_editar(): void
    {
        Sanctum::actingAs($this->staff);
        $ajena = $this->metodo($this->otroTenant, 'Daviplata');
        $pago  = $this->pagoHistorico($this->tenant, $this->cliente, 'Efectivo');

        $this->registrar(['payment_method_id' => $ajena->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method_id');

        $this->putJson("/api/billing/payments/{$pago->id}", ['payment_method_id' => $ajena->id])
            ->assertStatus(422);

        $this->assertNull($this->fresco($pago)->payment_method_id);
        $this->assertSame(1, Payment::withoutGlobalScope('tenant')->count(), 'No se creó ningún pago.');
    }

    #[Test]
    public function un_tenant_id_ajeno_en_el_cuerpo_no_logra_enlazar_formas_de_pago_cruzadas(): void
    {
        // registerPayment toma tenant_id del cuerpo (deuda previa, fuera de
        // KAN-109). La forma de pago se resuelve contra el tenant del PAGO, así
        // que el enlace nunca cruza tenants aunque ese dato venga manipulado.
        Sanctum::actingAs($this->staff);
        $propia = $this->metodo($this->tenant, 'Efectivo');

        $this->registrar(['tenant_id' => $this->otroTenant->id, 'payment_method_id' => $propia->id])
            ->assertStatus(422);

        $this->assertSame(0, Payment::withoutGlobalScope('tenant')->count());
    }

    #[Test]
    public function el_filtro_con_un_id_de_otro_tenant_no_devuelve_nada(): void
    {
        $ajena = $this->metodo($this->otroTenant, 'Daviplata');
        $this->pagoHistorico($this->otroTenant, $this->customer($this->otroTenant), 'Daviplata', ['payment_method_id' => $ajena->id]);

        Sanctum::actingAs($this->staff);

        $this->getJson("/api/billing/payments?payment_method_id={$ajena->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function el_relleno_nunca_enlaza_con_el_catalogo_de_otro_tenant(): void
    {
        $this->metodo($this->otroTenant, 'Daviplata');
        $pago = $this->pagoHistorico($this->tenant, $this->cliente, 'Daviplata');

        $this->artisan('payments:link-methods', ['--apply' => true])->assertSuccessful();

        $this->assertNull($this->fresco($pago)->payment_method_id);
    }

    #[Test]
    public function la_api_partner_expone_el_enlace_sin_cambiar_method(): void
    {
        $nequi = $this->metodo($this->tenant, 'Nequi Empresarial');
        $this->pagoHistorico($this->tenant, $this->cliente, 'Nequi', ['payment_method_id' => $nequi->id]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'CRM', 'is_active' => true]);
        $token  = $client->createToken('test', ['read:billing']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $this->getJson('/api/v1/partner/payments', ['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->assertOk()
            ->assertJsonPath('data.0.method', 'Nequi')
            ->assertJsonPath('data.0.payment_method_id', $nequi->id)
            ->assertJsonPath('data.0.payment_method_name', 'Nequi Empresarial');
    }

    // ── Relleno, comando y rollback ──────────────────────────────────────

    #[Test]
    public function la_migracion_enlaza_solo_coincidencias_unicas_sin_tocar_texto_ni_fechas(): void
    {
        $efectivo = $this->metodo($this->tenant, 'Efectivo');
        $inactiva = $this->metodo($this->tenant, 'Corresponsal', active: false);

        $a = $this->pagoHistorico($this->tenant, $this->cliente, 'efectivo');
        $b = $this->pagoHistorico($this->tenant, $this->cliente, 'Corresponsal');
        $c = $this->pagoHistorico($this->tenant, $this->cliente, 'Nequi viejo');
        $antes = DB::table('payments')->orderBy('id')->get(['id', 'method', 'updated_at'])->toArray();

        // Estado previo al despliegue: sin columna. Luego, la migración real.
        $this->migracion()->down();
        $this->migracion()->up();

        $this->assertSame($efectivo->id, (int) $this->fresco($a)->payment_method_id);
        $this->assertSame($inactiva->id, (int) $this->fresco($b)->payment_method_id, 'Desactivar no borra la historia.');
        $this->assertNull($this->fresco($c)->payment_method_id, 'Un nombre viejo sin dueño se queda como histórico.');

        $this->assertEquals(
            $antes,
            DB::table('payments')->orderBy('id')->get(['id', 'method', 'updated_at'])->toArray(),
            'El relleno no puede reescribir el texto ni la fecha de modificación de ningún pago.'
        );
    }

    #[Test]
    public function revertir_la_migracion_quita_la_columna_y_conserva_todos_los_pagos(): void
    {
        $efectivo = $this->metodo($this->tenant, 'Efectivo');
        $pago     = $this->pagoHistorico($this->tenant, $this->cliente, 'Efectivo', ['payment_method_id' => $efectivo->id]);

        $this->migracion()->down();

        $this->assertFalse(Schema::hasColumn('payments', 'payment_method_id'));
        $this->assertSame('Efectivo', DB::table('payments')->where('id', $pago->id)->value('method'));
        $this->assertSame(1, DB::table('payments')->count());

        // Y volver a migrar recupera el enlace.
        $this->migracion()->up();
        $this->assertSame($efectivo->id, (int) $this->fresco($pago)->payment_method_id);
    }

    #[Test]
    public function el_comando_sin_apply_solo_reporta_y_con_apply_enlaza(): void
    {
        $efectivo = $this->metodo($this->tenant, 'Efectivo');
        $pago     = $this->pagoHistorico($this->tenant, $this->cliente, 'Efectivo');
        $this->pagoHistorico($this->tenant, $this->cliente, 'Nequi viejo');

        $this->artisan('payments:link-methods')
            ->expectsOutputToContain('sólo lectura')
            ->expectsOutputToContain('1 pagos se enlazarían; 1 quedarían con su texto histórico')
            ->assertSuccessful();
        $this->assertNull($this->fresco($pago)->payment_method_id, 'Sin --apply no se escribe nada.');

        $this->artisan('payments:link-methods', ['--apply' => true])
            ->expectsOutputToContain('1 pagos enlazados')
            ->assertSuccessful();
        $this->assertSame($efectivo->id, (int) $this->fresco($pago)->payment_method_id);

        // Idempotente: una segunda pasada no toca nada más.
        $this->artisan('payments:link-methods', ['--apply' => true])
            ->expectsOutputToContain('0 pagos enlazados')
            ->assertSuccessful();
    }

    #[Test]
    public function el_comando_funciona_antes_de_la_migracion_para_el_reporte_previo(): void
    {
        $this->metodo($this->tenant, 'Efectivo');
        $this->pagoHistorico($this->tenant, $this->cliente, 'Efectivo');
        $this->migracion()->down();

        $this->artisan('payments:link-methods')
            ->expectsOutputToContain('1 pagos se enlazarían')
            ->assertSuccessful();

        $this->artisan('payments:link-methods', ['--apply' => true])->assertFailed();
    }

    // ── Borrado del catálogo e instalaciones ─────────────────────────────

    #[Test]
    public function borrar_la_forma_de_pago_deja_el_pago_con_su_texto(): void
    {
        Sanctum::actingAs($this->staff);
        $nequi = $this->metodo($this->tenant, 'Nequi');
        $pago  = Payment::find($this->registrar(['payment_method_id' => $nequi->id])->json('id'));

        $this->deleteJson("/api/billing/payment-methods/{$nequi->id}")->assertOk();

        $fila = $this->fresco($pago);
        $this->assertNull($fila->payment_method_id);
        $this->assertSame('Nequi', $fila->method);
    }

    #[Test]
    public function el_cobro_de_instalacion_enlaza_su_pago_y_no_lo_desenlaza_al_volver_a_guardar(): void
    {
        $efectivo = $this->metodo($this->tenant, 'Efectivo');

        $instalacion = CustomerInstallation::create([
            'tenant_id'         => $this->tenant->id,
            'customer_id'       => $this->cliente->id,
            'scheduled_date'    => now()->toDateString(),
            'address'           => 'Calle 1',
            'status'            => 'pendiente',
            'installation_cost' => 100000,
            'payment_received'  => 100000,
            'payment_method'    => 'Efectivo',
        ]);

        $servicio = app(InstallationBillingService::class);
        $servicio->upsertInstallationInvoice($instalacion, $this->tenant->id);

        $pago = Payment::withoutGlobalScope('tenant')->where('customer_id', $this->cliente->id)->sole();
        $this->assertSame($efectivo->id, (int) $pago->payment_method_id);

        // Se renombra el catálogo y la orden se vuelve a guardar con su texto de siempre.
        $efectivo->update(['name' => 'Efectivo en caja']);
        $instalacion->update(['payment_received' => 90000]);
        $servicio->upsertInstallationInvoice($instalacion->refresh(), $this->tenant->id);

        $fila = $this->fresco($pago);
        $this->assertSame($efectivo->id, (int) $fila->payment_method_id);
        $this->assertSame('Efectivo', $fila->method);
        $this->assertEquals(90000, $fila->amount);
    }
}
