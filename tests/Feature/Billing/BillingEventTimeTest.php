<?php

namespace Tests\Feature\Billing;

use App\Mail\PaymentReminderMail;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use App\Services\PaymentReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the hour-of-day gating added on top of the existing day-of-month
 * billing automation. The operator can now pick the HOUR at which invoices are
 * generated and reminders are sent (the cut already had cut_time). Default
 * '00:00:00' must preserve the date-only behaviour — that is covered by the
 * existing suites running at 09:00; here we exercise non-midnight hours.
 */
class BillingEventTimeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePlan(Tenant $tenant): Plan
    {
        return Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => 50000,
            'is_courtesy'  => false,
        ]);
    }

    /**
     * Build a router + billing config + one active, billable customer.
     *
     * @return array{tenant: Tenant, router: Router, customer: User}
     */
    private function scenario(array $billingAttrs): array
    {
        $this->seq++;
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);

        $config = Billing::create(array_merge(['status' => 'pending'], $billingAttrs));
        $router = Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);

        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id'   => $customer->id,
            'name'      => "Cliente{$this->seq}",
            'last_name' => "Apellido{$this->seq}",
            'router_id' => $router->id,
            'status'    => true,
        ]);
        UserService::create([
            'user_id'         => $customer->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => Carbon::now(),
        ]);

        return compact('tenant', 'router', 'customer');
    }

    // ── Invoice generation hour gate ────────────────────────────────────

    #[Test]
    public function invoice_is_not_generated_before_the_create_invoice_time(): void
    {
        // Create day reached, but it's 08:00 and the configured hour is 14:00.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 8, 0, 0));

        $this->scenario([
            'create_invoice'      => Carbon::create(2026, 1, 15)->toDateString(),
            'create_invoice_time' => '14:00:00',
        ]);

        $count = app(BillingService::class)->generateMonthlyInvoices();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function invoice_is_generated_once_the_create_invoice_time_has_passed(): void
    {
        // 14:30 on the create day, configured hour 14:00 → should fire.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 14, 30, 0));

        ['customer' => $customer] = $this->scenario([
            'create_invoice'      => Carbon::create(2026, 1, 15)->toDateString(),
            'create_invoice_time' => '14:00:00',
        ]);

        $count = app(BillingService::class)->generateMonthlyInvoices();

        $this->assertSame(1, $count);
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
    }

    #[Test]
    public function explicit_period_backfill_ignores_the_create_invoice_time(): void
    {
        // Manual backfill ("genera ahora") must not be blocked by the hour gate,
        // even at 08:00 with a 23:00 configured hour.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 8, 0, 0));

        ['customer' => $customer] = $this->scenario([
            'create_invoice'      => Carbon::create(2026, 1, 15)->toDateString(),
            'create_invoice_time' => '23:00:00',
        ]);

        $count = app(BillingService::class)->generateMonthlyInvoices('2026-06');

        $this->assertSame(1, $count);
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
    }

    // ── Reminder hour gate ──────────────────────────────────────────────

    #[Test]
    public function reminder_is_not_sent_before_the_payment_reminder_time(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 7, 0, 0));

        ['customer' => $customer, 'tenant' => $tenant] = $this->scenario([
            'payment_reminder'      => Carbon::create(2026, 1, 10)->toDateString(),
            'payment_reminder_time' => '10:00:00',
        ]);
        $this->makeOutstandingInvoice($tenant, $customer);

        $stats = app(PaymentReminderService::class)->sendDueReminders();

        $this->assertSame(0, $stats['reminded']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function reminder_is_sent_once_the_payment_reminder_time_has_passed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 10, 5, 0));

        ['customer' => $customer, 'tenant' => $tenant] = $this->scenario([
            'payment_reminder'      => Carbon::create(2026, 1, 10)->toDateString(),
            'payment_reminder_time' => '10:00:00',
        ]);
        $this->makeOutstandingInvoice($tenant, $customer);

        $stats = app(PaymentReminderService::class)->sendDueReminders();

        $this->assertSame(1, $stats['reminded']);
        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($customer->email));
    }

    // ── No-show audit must respect the hour gate ────────────────────────

    #[Test]
    public function audit_does_not_flag_no_show_before_the_create_time(): void
    {
        // Day reached but 08:00 < 14:00 configured hour → still pending, not a
        // false no-show (generation legitimately hasn't run yet).
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 8, 0, 0));

        ['router' => $router] = $this->scenario([
            'create_invoice'      => Carbon::create(2026, 1, 15)->toDateString(),
            'create_invoice_time' => '14:00:00',
        ]);

        $row = $this->rowFor(app(BillingService::class)->auditMonthlyBilling(), $router->id);

        $this->assertSame('pending', $row['status']);
        $this->assertFalse($row['due']);
    }

    #[Test]
    public function audit_flags_no_show_after_the_create_time_grace(): void
    {
        // 15:30 is past 14:00 + 1h grace → a due router with zero invoices is a
        // genuine no-show.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 15, 30, 0));

        ['router' => $router] = $this->scenario([
            'create_invoice'      => Carbon::create(2026, 1, 15)->toDateString(),
            'create_invoice_time' => '14:00:00',
        ]);

        $row = $this->rowFor(app(BillingService::class)->auditMonthlyBilling(), $router->id);

        $this->assertSame('no_show', $row['status']);
        $this->assertTrue($row['due']);
        $this->assertSame(1, $row['expected']);
        $this->assertSame(0, $row['actual']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeOutstandingInvoice(Tenant $tenant, User $customer): Invoice
    {
        $this->seq++;

        return Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'number'       => "INV-T{$this->seq}",
            'issue_date'   => Carbon::create(2026, 6, 1),
            'due_date'     => Carbon::create(2026, 6, 10),
            'period_start' => Carbon::create(2026, 6, 1),
            'period_end'   => Carbon::create(2026, 6, 30),
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ]);
    }

    private function rowFor(array $rows, int $routerId): array
    {
        foreach ($rows as $r) {
            if ($r['router_id'] === $routerId) {
                return $r;
            }
        }
        $this->fail("No audit row for router {$routerId}");
    }
}
