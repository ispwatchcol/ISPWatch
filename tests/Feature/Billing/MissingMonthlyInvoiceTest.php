<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoiceCreatedMail;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Incidente 2026-10-01: clientes sin la mensualidad del periodo, algunos con
 * el pago ya registrado como saldo a favor.
 *
 * Datos ficticios, base aislada (SQLite en memoria). Ninguna prueba toca
 * producción ni reproduce nombres reales.
 */
class MissingMonthlyInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->billing = app(BillingService::class);
        // Día 15 a las 9:00, con la corrida configurada el 1: ya le tocaba.
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 9, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{tenant: Tenant, router: Router, customer: User, profile: CustomerProfile, plan: Plan} */
    private function scenario(array $profile = [], float $planCost = 50000, ?Tenant $tenant = null, ?Router $router = null): array
    {
        $this->seq++;
        $tenant ??= Tenant::factory()->create();

        if (!$router) {
            $config = Billing::create([
                'create_invoice'    => Carbon::create(2026, 1, 1)->toDateString(),
                'payment_day'       => Carbon::create(2026, 1, 10)->toDateString(),
                'notification_type' => 'email',
                'status'            => 'pending',
            ]);

            $router = Router::create([
                'name'              => "Router {$this->seq}",
                'tenant_id'         => $tenant->id,
                'billing_router_id' => $config->id,
                'status'            => 'active',
            ]);
        }

        $plan = Plan::factory()->create([
            'name'         => "Plan ficticio {$this->seq}",
            'tenant_id'    => $tenant->id,
            'cost_product' => $planCost,
            'is_courtesy'  => false,
        ]);

        $start    = Carbon::create(2026, 1, 10);
        $customer = User::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $start]);

        $profile = CustomerProfile::create(array_merge([
            'user_id'           => $customer->id,
            'tenant_id'         => $tenant->id,
            'name'              => "Cliente{$this->seq}",
            'last_name'         => "Ficticio{$this->seq}",
            'router_id'         => $router->id,
            'status'            => true,
            'service_status'    => 'activo',
            'installation_date' => $start->toDateString(),
        ], $profile));

        UserService::create([
            'user_id'         => $customer->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => $start,
        ]);

        return compact('tenant', 'router', 'customer', 'profile', 'plan');
    }

    private function monthlyInvoicesOf(User $customer)
    {
        // Mismo criterio que la corrida: tipo mensual o sin tipo (filas viejas
        // y las de billing:generate-tenant, que no lo fija).
        return Invoice::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('invoice_type', Invoice::TYPE_MONTHLY)->orWhereNull('invoice_type'))
            ->get();
    }

    // ── Reproducción del reporte ──────────────────────────────────────────

    #[Test]
    public function a_customer_with_notifications_off_still_gets_the_monthly_invoice_and_no_notice(): void
    {
        ['customer' => $customer] = $this->scenario(['notify_invoice' => false]);

        $this->billing->generateMonthlyInvoices();

        $invoices = $this->monthlyInvoicesOf($customer);
        $this->assertCount(1, $invoices, 'Silenciar el aviso no puede dejar al cliente sin factura.');
        $this->assertEquals(50000, (float) $invoices->first()->total);
        Mail::assertNothingSent();
    }

    #[Test]
    public function an_eligible_customer_with_normal_settings_gets_the_invoice_and_the_notice(): void
    {
        ['customer' => $customer] = $this->scenario();

        $this->billing->generateMonthlyInvoices();

        $this->assertCount(1, $this->monthlyInvoicesOf($customer));
        Mail::assertSent(InvoiceCreatedMail::class, fn ($m) => $m->hasTo($customer->email));
    }

    #[Test]
    public function customers_that_are_not_eligible_get_no_invoice_and_the_diagnosis_says_why(): void
    {
        $excluido  = $this->scenario(['exclude_from_billing' => true]);
        $retirado  = $this->scenario(['service_status' => 'retirado']);
        $cortesia  = $this->scenario();
        $cortesia['plan']->update(['is_courtesy' => true]);

        $this->billing->generateMonthlyInvoices();

        foreach ([$excluido, $retirado, $cortesia] as $caso) {
            $this->assertCount(0, $this->monthlyInvoicesOf($caso['customer']));
        }

        $this->assertSame('excluded_from_billing', $this->explain($excluido)['reason']);
        $this->assertSame('service_status', $this->explain($retirado)['reason']);
        $this->assertSame('courtesy_plan', $this->explain($cortesia)['reason']);
        $this->assertSame('not_applicable', $this->explain($cortesia)['decision']);
    }

    #[Test]
    public function the_diagnosis_agrees_with_the_monthly_run_customer_by_customer(): void
    {
        // Mismo router, casos mezclados. Lo que explain() llama `missing` es
        // exactamente lo que la corrida emite; lo demás, la corrida lo deja.
        ['router' => $router, 'tenant' => $tenant] = $normal = $this->scenario();
        $silenciado = $this->scenario(['notify_invoice' => false], tenant: $tenant, router: $router);
        $excluido   = $this->scenario(['exclude_from_billing' => true], tenant: $tenant, router: $router);
        $nuevo      = $this->scenario(['installation_date' => '2026-10-05'], tenant: $tenant, router: $router);
        $nuevo['customer']->forceFill(['created_at' => Carbon::create(2026, 10, 5)])->save();
        UserService::where('user_id', $nuevo['customer']->id)->update(['start_date' => '2026-10-05']);

        $casos  = [$normal, $silenciado, $excluido, $nuevo];
        $antes  = collect($casos)->mapWithKeys(fn ($c) => [$c['customer']->id => $this->explain($c)['decision']]);

        $this->billing->generateMonthlyInvoices();

        foreach ($casos as $c) {
            $this->assertSame(
                $antes[$c['customer']->id] === 'missing' ? 1 : 0,
                $this->monthlyInvoicesOf($c['customer'])->count(),
                "Diagnóstico y corrida discrepan para el cliente {$c['customer']->id}."
            );
        }

        $this->assertSame(['missing', 'missing', 'not_applicable', 'not_applicable'], $antes->values()->all());
    }

    // ── Idempotencia y concurrencia ───────────────────────────────────────

    #[Test]
    public function running_the_monthly_job_again_does_not_duplicate(): void
    {
        ['customer' => $customer] = $this->scenario();

        $this->billing->generateMonthlyInvoices();
        $this->billing->generateMonthlyInvoices();
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $this->billing->generateMonthlyInvoices();

        $this->assertCount(1, $this->monthlyInvoicesOf($customer));
    }

    #[Test]
    public function a_second_writer_that_already_passed_the_check_finds_the_invoice_under_the_lock(): void
    {
        // La carrera real: dos procesos pasaron el «¿ya existe?» de afuera antes
        // de que ninguno escribiera. Se llama dos veces al punto de escritura,
        // que es lo que harían los dos; el segundo tiene que salir sin crear y
        // sin volver a aplicar el saldo a favor. (La concurrencia de verdad sólo
        // se ve en PostgreSQL; aquí se prueba la segunda comprobación.)
        ['customer' => $customer, 'router' => $router, 'profile' => $profile] = $this->scenario();
        $this->pay($customer, 20000);

        $escribir = fn () => $this->invokeCreate($customer, $router, $profile);

        $escribir();

        try {
            $escribir();
            $this->fail('El segundo escritor debía encontrar la factura del primero.');
        } catch (\App\Billing\MonthlyInvoiceAlreadyExists $e) {
            $this->assertSame((int) $this->monthlyInvoicesOf($customer)->first()->id, (int) $e->invoice->id);
        }

        $this->assertCount(1, $this->monthlyInvoicesOf($customer));
        $this->assertSame(1, \App\Models\CustomerCredit::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('type', \App\Models\CustomerCredit::TYPE_APPLIED)->count());
        $this->assertEquals(0, (float) $profile->fresh()->credit_balance);
    }

    #[Test]
    public function a_repair_that_races_the_monthly_job_does_not_duplicate(): void
    {
        ['customer' => $customer, 'router' => $router, 'profile' => $profile] = $this->scenario();

        $caso = ['profile' => $profile, 'router' => $router, 'customer' => $customer];
        $this->assertSame('missing', $this->explain($caso)['decision']);
        $aprobado = $this->approvedHash($caso);

        // La corrida horaria gana la carrera...
        $this->billing->generateMonthlyInvoices();

        // ...y el plan aprobado ya no describe la realidad: aborta sin escribir.
        $this->expectPlanChanged(fn () => $this->applyPlan([$caso], $aprobado));

        $this->assertCount(1, $this->monthlyInvoicesOf($customer));
    }

    // ── El plan aprobado no se aplica obsoleto ────────────────────────────

    #[Test]
    public function a_payment_after_approval_aborts_the_whole_batch_without_writing(): void
    {
        $uno = $this->scenario();
        $dos = $this->scenario(tenant: $uno['tenant'], router: $uno['router']);
        $this->pay($uno['customer'], 20000);

        $aprobado = $this->approvedHash($uno, $dos);

        // Entra un pago de uno de los dos: su saldo a favor ya no es el
        // aprobado. El lote entero se aborta, también para el que no cambió.
        $this->pay($dos['customer'], 10000);
        $antes = $this->dbSnapshot();

        $this->expectPlanChanged(fn () => $this->applyPlan([$uno, $dos], $aprobado));

        $this->assertSame($antes, $this->dbSnapshot());
        $this->assertCount(0, $this->monthlyInvoicesOf($uno['customer']));
        $this->assertCount(0, $this->monthlyInvoicesOf($dos['customer']));
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_plan_approved_on_another_day_is_not_applied(): void
    {
        $caso     = $this->scenario();
        $aprobado = $this->approvedHash($caso);

        // Al día siguiente cambian la emisión y quizá el vencimiento: nueva simulación.
        Carbon::setTestNow(Carbon::create(2026, 9, 16, 9, 0, 0));

        $this->expectPlanChanged(fn () => $this->applyPlan([$caso], $aprobado));
        $this->assertCount(0, $this->monthlyInvoicesOf($caso['customer']));
    }

    #[Test]
    public function a_change_in_the_plan_price_after_approval_aborts(): void
    {
        $caso     = $this->scenario();
        $aprobado = $this->approvedHash($caso);

        $caso['plan']->update(['cost_product' => 55000]);

        $this->expectPlanChanged(fn () => $this->applyPlan([$caso], $aprobado));
        $this->assertCount(0, $this->monthlyInvoicesOf($caso['customer']));
    }

    #[Test]
    public function a_batch_rolled_back_by_an_outer_transaction_sends_no_notice(): void
    {
        // Los avisos van después del COMMIT del lote: si algo lo deshace, el
        // cliente no puede haber recibido una factura que no existe.
        $caso     = $this->scenario();
        $aprobado = $this->approvedHash($caso);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($caso, $aprobado) {
                $this->applyPlan([$caso], $aprobado);
                throw new \RuntimeException('se deshace a propósito');
            });
        } catch (\RuntimeException) {
        }

        $this->assertCount(0, $this->monthlyInvoicesOf($caso['customer']));
        Mail::assertNothingSent();

        // Y confirmado, sí avisa.
        $this->applyPlan([$caso], $aprobado);
        Mail::assertSent(InvoiceCreatedMail::class, fn ($m) => $m->hasTo($caso['customer']->email));
    }

    // ── billing:generate-tenant pasa por la misma puerta ──────────────────

    #[Test]
    public function generate_tenant_does_not_duplicate_a_prorated_month_or_a_second_run(): void
    {
        // Un cliente instalado a mitad de mes tiene la mensualidad con
        // period_start = día de instalación. El filtro propio del comando
        // (periodo exacto) no la ve; la puerta común sí.
        $prorrateado = $this->scenario();
        $this->billing->generateMonthlyInvoices();
        \App\Models\Invoice::withoutGlobalScopes()->where('customer_id', $prorrateado['customer']->id)
            ->update(['period_start' => '2026-09-12']);

        $nuevo = $this->scenario(tenant: $prorrateado['tenant'], router: $prorrateado['router']);

        $this->artisan('billing:generate-tenant', ['tenant' => $prorrateado['tenant']->id, 'period' => '2026-09'])
            ->expectsOutputToContain('Already had the month: 1')
            ->assertSuccessful();
        $this->artisan('billing:generate-tenant', ['tenant' => $prorrateado['tenant']->id, 'period' => '2026-09'])
            ->assertSuccessful();

        $this->assertCount(1, $this->monthlyInvoicesOf($prorrateado['customer']));
        $this->assertCount(1, $this->monthlyInvoicesOf($nuevo['customer']));
    }

    // ── Saldo a favor: se usa, no se inventa ──────────────────────────────

    public static function creditCases(): array
    {
        //            pagó   total  aplica saldo-factura  crédito-después
        return [
            'parcial'  => [20000, 50000, 20000, 30000, 0],
            'exacto'   => [50000, 50000, 50000, 0,     0],
            'superior' => [80000, 50000, 50000, 0,     30000],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('creditCases')]
    public function the_repair_applies_the_existing_credit_without_creating_a_payment(
        float $pagado, float $total, float $aplica, float $saldoFactura, float $creditoDespues
    ): void {
        ['customer' => $customer, 'router' => $router, 'profile' => $profile] = $this->scenario();

        // El cliente pagó sin factura: el dinero quedó como saldo a favor.
        $this->pay($customer, $pagado);
        $this->assertEquals($pagado, (float) $profile->fresh()->credit_balance);

        $pagosAntes = $this->paymentsSnapshot($customer);

        $preview = $this->explain(['profile' => $profile->fresh(), 'router' => $router])['preview'];
        $this->assertEquals($total, $preview['total']);
        $this->assertEquals($aplica, $preview['credit_to_apply']);
        $this->assertEquals($saldoFactura, $preview['balance_due']);
        $this->assertEquals($creditoDespues, $preview['credit_after']);

        $caso = ['profile' => $profile, 'router' => $router, 'customer' => $customer];
        $r = $this->applyPlan([$caso], $this->approvedHash($caso))[0];
        $this->assertTrue($r['applied']);

        $invoice = $this->monthlyInvoicesOf($customer)->first();
        $this->assertEquals($total, (float) $invoice->total);
        $this->assertEquals($saldoFactura, (float) $invoice->balance_due);
        $this->assertSame($saldoFactura > 0 ? 'partial' : 'paid', $invoice->status);
        $this->assertEquals($creditoDespues, (float) $profile->fresh()->credit_balance);

        // Ningún ingreso nuevo, y los pagos y sus asignaciones como estaban.
        $this->assertSame($pagosAntes, $this->paymentsSnapshot($customer));
    }

    #[Test]
    public function repeating_the_repair_does_not_apply_the_credit_twice(): void
    {
        ['customer' => $customer, 'router' => $router, 'profile' => $profile] = $this->scenario();
        $this->pay($customer, 80000);

        $caso     = ['profile' => $profile, 'router' => $router, 'customer' => $customer];
        $aprobado = $this->approvedHash($caso);
        $this->applyPlan([$caso], $aprobado);

        // Repetir con el MISMO plan aprobado: ya no describe la realidad.
        $this->expectPlanChanged(fn () => $this->applyPlan([$caso], $aprobado));

        // Con una simulación nueva el plan está vacío: no hay nada que emitir.
        $segunda = $this->applyPlan([$caso], $this->approvedHash($caso))[0];
        $this->assertFalse($segunda['applied']);
        $this->assertSame('invoice_present', $segunda['reason']);
        $this->assertCount(1, $this->monthlyInvoicesOf($customer));
        $this->assertEquals(30000, (float) $profile->fresh()->credit_balance);
        $this->assertSame(1, \App\Models\CustomerCredit::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('type', \App\Models\CustomerCredit::TYPE_APPLIED)->count());
    }

    #[Test]
    public function the_repair_respects_the_notification_preference(): void
    {
        $silenciado = $this->scenario(['notify_invoice' => false]);
        $normal     = $this->scenario();
        $this->applyPlan([$silenciado], $this->approvedHash($silenciado));
        Mail::assertNothingSent();

        $this->applyPlan([$normal], $this->approvedHash($normal));
        Mail::assertSent(InvoiceCreatedMail::class, fn ($m) => $m->hasTo($normal['customer']->email));
        Mail::assertNotSent(InvoiceCreatedMail::class, fn ($m) => $m->hasTo($silenciado['customer']->email));
    }

    // ── Lo que NO es una faltante ─────────────────────────────────────────

    #[Test]
    public function a_voided_invoice_is_reported_and_not_reissued(): void
    {
        ['customer' => $customer, 'router' => $router, 'profile' => $profile] = $this->scenario();
        $this->billing->generateMonthlyInvoices();

        $invoice = $this->monthlyInvoicesOf($customer)->first();
        $this->billing->voidInvoice($invoice, 'prueba de anulación');

        $row = $this->explain(['profile' => $profile->fresh(), 'router' => $router]);
        $this->assertSame('present', $row['decision']);
        $this->assertSame('invoice_voided', $row['reason']);

        $caso = ['profile' => $profile, 'router' => $router, 'customer' => $customer];
        $r = $this->applyPlan([$caso], $this->approvedHash($caso))[0];
        $this->assertFalse($r['applied']);
        $this->assertCount(1, $this->monthlyInvoicesOf($customer));
    }

    #[Test]
    public function a_period_whose_run_has_not_come_yet_is_not_missing(): void
    {
        $caso = $this->scenario();
        $caso['router']->billingConfig->update(['create_invoice' => Carbon::create(2026, 1, 20)->toDateString()]);

        $row = $this->billing->explainMonthlyInvoice($caso['profile'], $caso['router']->fresh('billingConfig'), Carbon::create(2026, 9, 1));

        $this->assertSame('not_due_yet', $row['reason']);
    }

    #[Test]
    public function a_router_without_creation_day_is_reported_not_repaired(): void
    {
        $caso = $this->scenario();
        $caso['router']->billingConfig->update(['create_invoice' => null]);

        $row = $this->billing->explainMonthlyInvoice($caso['profile'], $caso['router']->fresh('billingConfig'), Carbon::create(2026, 9, 1));

        $this->assertSame('router_without_create_day', $row['reason']);
        $this->assertSame('not_applicable', $row['decision']);
    }

    #[Test]
    public function two_service_points_with_the_same_name_are_separate_customers(): void
    {
        // «PUNTO 2»: mismo titular de nombre, registro distinto. Reparar uno no
        // puede tocar al otro.
        $uno = $this->scenario(['name' => 'Titular', 'last_name' => 'Ficticio']);
        $dos = $this->scenario(['name' => 'Titular', 'last_name' => 'Ficticio PUNTO 2'], tenant: $uno['tenant'], router: $uno['router']);

        $this->applyPlan([$uno], $this->approvedHash($uno));

        $this->assertCount(1, $this->monthlyInvoicesOf($uno['customer']));
        $this->assertCount(0, $this->monthlyInvoicesOf($dos['customer']));
        $this->assertSame('missing', $this->explain($dos)['decision']);
    }

    // ── El comando: simulación, aprobación y aislamiento ──────────────────

    #[Test]
    public function the_dry_run_writes_nothing(): void
    {
        $caso = $this->scenario();
        $this->pay($caso['customer'], 20000);

        $antes = $this->dbSnapshot();

        $this->artisan('billing:missing-invoices', ['--tenant' => $caso['tenant']->id, '--period' => '2026-09'])
            ->expectsOutputToContain('1 mensualidad(es) faltante(s)')
            ->expectsOutputToContain('Simulación: no se escribió nada.')
            ->assertSuccessful();

        $this->assertSame($antes, $this->dbSnapshot());
        Mail::assertNothingSent();
    }

    #[Test]
    public function apply_refuses_without_the_approved_plan_hash_and_writes_nothing(): void
    {
        $caso  = $this->scenario();
        $antes = $this->dbSnapshot();

        $this->artisan('billing:missing-invoices', [
            '--tenant' => $caso['tenant']->id, '--period' => '2026-09',
            '--customer' => [$caso['customer']->id], '--apply' => true,
            '--plan-hash' => str_repeat('0', 64), '--reason' => 'prueba',
        ])->expectsOutputToContain('El plan cambió')->assertFailed();

        $this->assertSame($antes, $this->dbSnapshot());
    }

    #[Test]
    public function apply_with_the_approved_hash_repairs_and_verifies(): void
    {
        $caso = $this->scenario();
        $this->pay($caso['customer'], 20000);

        $hash = $this->planHashFor($caso['tenant']->id, [$caso['customer']->id]);

        $this->artisan('billing:missing-invoices', [
            '--tenant' => $caso['tenant']->id, '--period' => '2026-09',
            '--customer' => [$caso['customer']->id], '--apply' => true,
            '--plan-hash' => $hash, '--reason' => 'Incidente de prueba',
        ])->expectsOutputToContain('0 siguen sin mensualidad')->assertSuccessful();

        $invoice = $this->monthlyInvoicesOf($caso['customer'])->first();
        $this->assertNotNull($invoice);
        $this->assertStringContainsString('Incidente de prueba', (string) $invoice->notes);
        $this->assertEquals(30000, (float) $invoice->balance_due);

        // Repetir con el mismo hash ya no coincide: el plan ahora está vacío.
        $this->artisan('billing:missing-invoices', [
            '--tenant' => $caso['tenant']->id, '--period' => '2026-09',
            '--customer' => [$caso['customer']->id], '--apply' => true,
            '--plan-hash' => $hash, '--reason' => 'Incidente de prueba',
        ])->assertFailed();

        $this->assertCount(1, $this->monthlyInvoicesOf($caso['customer']));
    }

    #[Test]
    public function the_command_never_crosses_tenants(): void
    {
        $nuestro = $this->scenario();
        $ajeno   = $this->scenario();

        // Un cliente ajeno nombrado explícitamente: error, nada evaluado.
        $this->artisan('billing:missing-invoices', [
            '--tenant' => $nuestro['tenant']->id, '--period' => '2026-09',
            '--customer' => [$ajeno['customer']->id],
        ])->expectsOutputToContain('no existen en el tenant')->assertExitCode(2);

        // La simulación del tenant lista sólo a los suyos.
        $this->artisan('billing:missing-invoices', [
            '--tenant' => $nuestro['tenant']->id, '--period' => '2026-09', '--json' => true,
        ])->expectsOutputToContain('"customer_id": ' . $nuestro['customer']->id)
          ->doesntExpectOutputToContain('"customer_id": ' . $ajeno['customer']->id)
          ->assertSuccessful();

        // Y una reparación del nuestro no toca al ajeno.
        $hash = $this->planHashFor($nuestro['tenant']->id, [$nuestro['customer']->id]);
        $this->artisan('billing:missing-invoices', [
            '--tenant' => $nuestro['tenant']->id, '--period' => '2026-09',
            '--customer' => [$nuestro['customer']->id], '--apply' => true,
            '--plan-hash' => $hash, '--reason' => 'prueba',
        ])->assertSuccessful();

        $this->assertCount(1, $this->monthlyInvoicesOf($nuestro['customer']));
        $this->assertCount(0, $this->monthlyInvoicesOf($ajeno['customer']));
    }

    // ── Recuperación: qué deshace exactamente anular una factura reparada ──

    #[Test]
    public function voiding_a_repaired_invoice_returns_credit_and_later_payments_but_not_everything(): void
    {
        // No es un rollback: esta prueba fija qué revierte y qué no, para que
        // el runbook no prometa más de lo que hace (§ 88, recuperación).
        $caso = $this->scenario();
        $this->pay($caso['customer'], 20000);
        $this->applyPlan([$caso], $this->approvedHash($caso));

        $invoice = $this->monthlyInvoicesOf($caso['customer'])->first();
        $this->assertEquals(30000, (float) $invoice->balance_due);
        Mail::assertSent(InvoiceCreatedMail::class, 1);   // el aviso ya salió: no se recupera

        // Después de la reparación, el cliente paga lo que quedaba.
        $this->pay($caso['customer'], 30000);
        $this->assertSame('paid', $invoice->fresh()->status);

        $numero = $invoice->number;
        $this->billing->voidInvoice($invoice->fresh(), 'Recuperación de prueba');

        $anulada = $invoice->fresh();
        $creditos = \App\Models\CustomerCredit::withoutGlobalScopes()
            ->where('customer_id', $caso['customer']->id)->orderBy('id')->get(['type', 'amount']);

        // Revierte: la factura queda sin efecto y conserva número, total y fechas.
        $this->assertSame('void', $anulada->status);
        $this->assertEquals(0, (float) $anulada->balance_due);
        $this->assertEquals(50000, (float) $anulada->total);
        $this->assertSame($numero, $anulada->number);
        $this->assertNotNull($anulada->voided_at);

        // El saldo vuelve, pero NO sólo el que consumió la reparación: también
        // el pago posterior que se le había aplicado pasa a saldo a favor. Una
        // anulación deshace aplicaciones posteriores; no es la inversa exacta.
        $this->assertEquals(50000, (float) $caso['profile']->fresh()->credit_balance);
        $this->assertTrue($creditos->contains(fn ($c) => $c->type === 'adjusted' && (float) $c->amount === 20000.0),
            'El crédito que consumió la reparación vuelve como AJUSTE, no borrando el movimiento original.');
        $this->assertTrue($creditos->contains(fn ($c) => $c->type === 'applied' && (float) $c->amount === -20000.0),
            'El movimiento original de la reparación se conserva en el historial.');

        // Los pagos no se tocan: siguen existiendo los dos.
        $this->assertSame(2, \App\Models\Payment::withoutGlobalScopes()->where('customer_id', $caso['customer']->id)->count());

        // NO revierte: el mes sigue «cubierto» por la anulada, así que ni la
        // corrida ni la herramienta lo vuelven a emitir solos.
        $this->assertSame('invoice_voided', $this->explain($caso)['reason']);

        // Y la anulación no avisa al cliente (no hay segundo correo).
        Mail::assertSent(InvoiceCreatedMail::class, 1);
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────

    /** La huella que aprobaría el responsable, a partir de la simulación. */
    private function approvedHash(array ...$casos): string
    {
        $filas = array_map(fn ($c) => $this->explain($c), $casos);

        return \App\Billing\MissingInvoicePlan::hash(
            array_values(array_filter($filas, fn ($r) => $r['decision'] === 'missing'))
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function applyPlan(array $casos, string $hash): array
    {
        return $this->billing->applyMissingInvoicePlan(
            array_map(fn ($c) => [$c['profile']->fresh(), $c['router']->fresh('billingConfig')], $casos),
            Carbon::create(2026, 9, 1),
            $hash,
            'prueba'
        );
    }

    private function expectPlanChanged(callable $aplicar): void
    {
        try {
            $aplicar();
            $this->fail('Se esperaba que el plan aprobado se rechazara por obsoleto.');
        } catch (\App\Billing\MissingInvoicePlanChanged $e) {
            $this->assertNotEmpty($e->currentHash);
        }
    }

    private function explain(array $caso): array
    {
        return $this->billing->explainMonthlyInvoice(
            $caso['profile']->fresh(),
            $caso['router']->fresh('billingConfig'),
            Carbon::create(2026, 9, 1)
        );
    }

    /** Un pago real, sin facturas abiertas: el dinero queda como saldo a favor. */
    private function pay(User $customer, float $amount): void
    {
        $this->billing->registerPayment([
            'tenant_id'    => $customer->tenant_id,
            'customer_id'  => $customer->id,
            'amount'       => $amount,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);
    }

    private function invokeCreate(User $customer, Router $router, CustomerProfile $profile): void
    {
        $plan   = UserService::where('user_id', $customer->id)->first()->servicePlan;
        $metodo = new \ReflectionMethod($this->billing, 'createMonthlyInvoiceFor');
        $metodo->setAccessible(true);
        $metodo->invoke(
            $this->billing,
            (int) $customer->tenant_id, (int) $customer->id, $router->fresh('billingConfig'), $profile->fresh(), $plan,
            Carbon::create(2026, 9, 15), Carbon::create(2026, 10, 10),
            Carbon::create(2026, 9, 1), Carbon::create(2026, 9, 30),
            $router->billingConfig,
        );
    }

    private function planHashFor(int $tenantId, array $customers): string
    {
        \Illuminate\Support\Facades\Artisan::call('billing:missing-invoices', [
            '--tenant' => $tenantId, '--period' => '2026-09', '--customer' => $customers, '--json' => true,
        ]);

        return json_decode(\Illuminate\Support\Facades\Artisan::output(), true)['plan_hash'];
    }

    /** Pagos y asignaciones del cliente, tal cual. */
    private function paymentsSnapshot(User $customer): array
    {
        $pagos = \App\Models\Payment::withoutGlobalScopes()->where('customer_id', $customer->id)
            ->orderBy('id')->get(['id', 'amount'])->toArray();
        $asignaciones = \Illuminate\Support\Facades\DB::table('payment_allocations')
            ->whereIn('payment_id', array_column($pagos, 'id'))->orderBy('id')->get(['id', 'invoice_id', 'amount'])
            ->map(fn ($r) => (array) $r)->all();

        return [$pagos, $asignaciones];
    }

    /** Conteo de todas las tablas que una reparación podría tocar. */
    private function dbSnapshot(): array
    {
        $db = \Illuminate\Support\Facades\DB::class;

        return collect([
            'invoices', 'invoice_items', 'payments', 'payment_allocations', 'customer_credits',
            'billing_action_logs', 'invoice_carryovers', 'audit_logs',
        ])->mapWithKeys(fn ($t) => [$t => $db::table($t)->count()])->all()
            + ['credit' => $db::table('customer_profile')->sum('credit_balance')];
    }
}
