<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Concurrencia REAL sobre PostgreSQL: dos procesos del sistema operativo, cada
 * uno con su conexión, sobre el mismo cliente y el mismo mes (§ 88).
 *
 * El escritor A abre transacción, bloquea al cliente y tarda antes de emitir.
 * Mientras tanto arranca B por cada camino que crea mensualidades. Con la
 * puerta común (withMonthlyInvoiceLock), B tiene que ESPERAR a que A confirme
 * y salir sin duplicar. Sin ella, B no espera —A todavía no insertó nada— y
 * emite una segunda mensualidad: la prueba es determinista en los dos sentidos.
 *
 * Sólo corre en el job de PostgreSQL del CI. En SQLite no hay bloqueo de filas
 * ni segundo proceso que vea los datos, así que se omite. No usa
 * RefreshDatabase: los hijos tienen que ver datos CONFIRMADOS, así que cada
 * prueba crea su propio tenant y lo borra entero al terminar.
 */
class MonthlyInvoiceConcurrencyPostgresTest extends TestCase
{
    private const HOLD_MS = 4000;

    private ?int $tenantId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrencia real: sólo en el job de PostgreSQL.');
        }

        if (!Schema::hasTable('invoices')) {
            $this->markTestSkipped('La base de PostgreSQL no está migrada.');
        }

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if ($this->tenantId !== null) {
            $this->purgeTenant($this->tenantId);
        }

        parent::tearDown();
    }

    #[Test]
    public function two_processes_creating_the_same_month_emit_one_invoice_and_apply_credit_once(): void
    {
        $c = $this->committedCustomer(credit: 20000);

        [$a, $b] = $this->race($this->writer('hold', $c), $this->writer('create', $c));

        $this->assertSame('created', $a['result']);
        $this->assertSame('already_exists', $b['result'], 'El segundo proceso tenía que encontrar la factura del primero.');
        $this->assertGreaterThanOrEqual(1000, $b['elapsed_ms'], 'El segundo proceso tenía que ESPERAR el bloqueo.');

        $this->assertMonthlyInvoices($c, 1);
        $this->assertSame(1, CustomerCredit::withoutGlobalScopes()
            ->where('customer_id', $c['customer_id'])->where('type', CustomerCredit::TYPE_APPLIED)->count());
        $this->assertEquals(0, (float) CustomerProfile::where('user_id', $c['customer_id'])->value('credit_balance'));
    }

    #[Test]
    public function the_monthly_run_waits_for_the_writer_and_does_not_duplicate(): void
    {
        $c = $this->committedCustomer();

        $this->race($this->writer('hold', $c), $this->artisan_('billing:generate-monthly', '2026-09'));

        $this->assertMonthlyInvoices($c, 1);
    }

    #[Test]
    public function generate_tenant_waits_for_the_writer_and_does_not_duplicate(): void
    {
        $c = $this->committedCustomer();

        [, $b] = $this->race(
            $this->writer('hold', $c),
            $this->artisan_('billing:generate-tenant', (string) $c['tenant_id'], '2026-09')
        );

        $this->assertStringContainsString('Already had the month: 1', $b['output']);
        $this->assertMonthlyInvoices($c, 1);
    }

    #[Test]
    public function an_approved_plan_that_loses_the_race_aborts_without_writing(): void
    {
        $c = $this->committedCustomer(credit: 20000);

        // Simulación aprobada ANTES de que A emita.
        $row = app(BillingService::class)->explainMonthlyInvoice(
            CustomerProfile::where('user_id', $c['customer_id'])->first(),
            Router::withoutGlobalScope('tenant')->with('billingConfig')->find($c['router_id']),
            Carbon::create(2026, 9, 1)
        );
        $this->assertSame('missing', $row['decision']);
        $hash = \App\Billing\MissingInvoicePlan::hash([$row]);

        [, $b] = $this->race(
            $this->writer('hold', $c),
            $this->artisan_(
                'billing:missing-invoices', "--tenant={$c['tenant_id']}", '--period=2026-09',
                "--customer={$c['customer_id']}", "--plan-hash={$hash}", '--reason=prueba de concurrencia', '--apply'
            )
        );

        $this->assertStringContainsString('El plan cambió', $b['output']);
        $this->assertNotSame(0, $b['exit']);
        $this->assertMonthlyInvoices($c, 1);
        $this->assertSame(1, CustomerCredit::withoutGlobalScopes()
            ->where('customer_id', $c['customer_id'])->where('type', CustomerCredit::TYPE_APPLIED)->count());
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────

    /**
     * Arranca A, espera a que tenga el bloqueo, arranca B y espera a los dos.
     * B tiene que terminar DESPUÉS que A: si el bloqueo no lo detuviera,
     * terminaría antes (A sigue durmiendo con el cliente bloqueado).
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function race(Process $a, Process $b): array
    {
        $a->start();
        $a->waitUntil(fn ($type, $out) => str_contains($out, 'LOCKED'));

        $b->start();
        $a->wait();
        $finA = microtime(true);
        $b->wait();
        $finB = microtime(true);

        $this->assertTrue($a->isSuccessful(), 'A falló: ' . $a->getErrorOutput() . $a->getOutput());
        $this->assertGreaterThanOrEqual($finA, $finB, 'B terminó antes que A: no esperó el bloqueo.');

        return [$this->parse($a), $this->parse($b)];
    }

    private function parse(Process $p): array
    {
        $resultado = ['output' => $p->getOutput() . $p->getErrorOutput(), 'exit' => $p->getExitCode()];

        if (preg_match('/^RESULT (\{.*\})$/m', $p->getOutput(), $m)) {
            $resultado += json_decode($m[1], true);
        }

        return $resultado;
    }

    private function writer(string $modo, array $c): Process
    {
        return new Process(
            [PHP_BINARY, base_path('tests/Support/Concurrency/monthly_invoice_writer.php'), $modo, json_encode([
                'customer_id' => $c['customer_id'],
                'router_id'   => $c['router_id'],
                'hold_ms'     => self::HOLD_MS,
            ])],
            base_path(),
            $this->childEnv(),
            null,
            60
        );
    }

    private function artisan_(string ...$args): Process
    {
        return new Process([PHP_BINARY, base_path('artisan'), ...$args], base_path(), $this->childEnv(), null, 60);
    }

    /** La conexión YA RESUELTA de esta prueba, explícita, y sin correo real. */
    private function childEnv(): array
    {
        $cfg = DB::connection()->getConfig();

        return [
            'APP_ENV'       => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST'       => (string) $cfg['host'],
            'DB_PORT'       => (string) ($cfg['port'] ?? '5432'),
            'DB_DATABASE'   => (string) $cfg['database'],
            'DB_USERNAME'   => (string) $cfg['username'],
            'DB_PASSWORD'   => (string) ($cfg['password'] ?? ''),
            'DB_SCHEMA'     => (string) ($cfg['schema'] ?? $cfg['search_path'] ?? 'public'),
            'DB_SSLMODE'    => (string) ($cfg['sslmode'] ?? 'disable'),
            'DB_URL'        => '',
            'MAIL_MAILER'   => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];
    }

    /** Cliente con todo CONFIRMADO en la base, para que los hijos lo vean. */
    private function committedCustomer(float $credit = 0): array
    {
        $tenant = Tenant::factory()->create();
        $this->tenantId = (int) $tenant->id;

        $config = new Billing([
            'create_invoice'    => '2026-01-01',
            'payment_day'       => '2026-01-10',
            'notification_type' => 'email',
            'status'            => 'pending',
        ]);
        $config->save();

        $router = new Router(['name' => 'Router concurrencia', 'billing_router_id' => $config->id, 'status' => 'active']);
        $router->tenant_id = $tenant->id;
        $router->save();

        $plan = Plan::factory()->make(['name' => 'Plan concurrencia', 'cost_product' => 50000, 'is_courtesy' => false]);
        $plan->tenant_id = $tenant->id;
        $plan->save();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'created_at' => '2026-01-10']);

        CustomerProfile::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'name' => 'Concurrencia', 'last_name' => 'Ficticio',
            'router_id' => $router->id, 'status' => true, 'service_status' => 'activo', 'installation_date' => '2026-01-10',
        ]);

        UserService::create([
            'user_id' => $user->id, 'service_plan_id' => $plan->id,
            'status' => UserService::STATUS_ACTIVE, 'start_date' => '2026-01-10',
        ]);

        if ($credit > 0) {
            app(BillingService::class)->registerPayment([
                'tenant_id' => $tenant->id, 'customer_id' => $user->id, 'amount' => $credit,
                'payment_date' => '2026-09-10', 'method' => 'cash',
            ]);
        }

        return ['tenant_id' => (int) $tenant->id, 'customer_id' => (int) $user->id, 'router_id' => (int) $router->id, 'billing_id' => (int) $config->id];
    }

    private function assertMonthlyInvoices(array $c, int $esperadas): void
    {
        $this->assertSame($esperadas, Invoice::withoutGlobalScopes()
            ->where('customer_id', $c['customer_id'])
            ->where(fn ($q) => $q->where('invoice_type', Invoice::TYPE_MONTHLY)->orWhereNull('invoice_type'))
            ->whereDate('period_start', '>=', '2026-09-01')
            ->whereDate('period_start', '<=', '2026-09-30')
            ->count());
    }

    /** Borra todo lo que creó la prueba: sus datos están confirmados. */
    private function purgeTenant(int $tenantId): void
    {
        $users    = DB::table('users')->where('tenant_id', $tenantId)->pluck('id')->all();
        $invoices = DB::table('invoices')->where('tenant_id', $tenantId)->pluck('id')->all();
        $payments = DB::table('payments')->where('tenant_id', $tenantId)->pluck('id')->all();
        $routers  = DB::table('router')->where('tenant_id', $tenantId)->pluck('billing_router_id')->filter()->all();

        DB::transaction(function () use ($tenantId, $users, $invoices, $payments, $routers) {
            DB::table('payment_allocations')->whereIn('payment_id', $payments)->delete();
            DB::table('customer_credits')->whereIn('customer_id', $users)->delete();
            DB::table('invoice_items')->whereIn('invoice_id', $invoices)->delete();

            foreach (['invoice_carryovers', 'billing_action_logs', 'partner_events', 'audit_logs', 'invoices', 'payments'] as $tabla) {
                if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'tenant_id')) {
                    DB::table($tabla)->where('tenant_id', $tenantId)->delete();
                }
            }

            DB::table('user_services')->whereIn('user_id', $users)->delete();
            DB::table('customer_profile')->whereIn('user_id', $users)->delete();
            DB::table('service_plan')->where('tenant_id', $tenantId)->delete();
            DB::table('router')->where('tenant_id', $tenantId)->delete();
            DB::table('billing')->whereIn('id', $routers)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
            DB::table('tenant')->where('id', $tenantId)->delete();
        });
    }
}
