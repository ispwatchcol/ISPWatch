<?php

namespace Tests\Feature\Billing;

use App\Mail\PaymentReminderMail;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the automated payment-reminder job:
 *   - fires on each router's billing.payment_reminder day (clamped to month)
 *   - emails customers with an outstanding invoice
 *   - is idempotent per billing cycle (invoices.last_reminder_sent)
 */
class PaymentReminderTest extends TestCase
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

    /**
     * @return array{tenant: Tenant, router: Router, customer: User, invoice: Invoice}
     */
    private function scenario(int $reminderDay, array $invoiceOverrides = []): array
    {
        $this->seq++;
        $tenant = Tenant::factory()->create();

        $config = Billing::create([
            'payment_reminder' => Carbon::create(2026, 1, $reminderDay)->toDateString(),
            'status'           => 'pending',
        ]);
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

        $invoice = Invoice::create(array_merge([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'number'       => "INV-{$this->seq}",
            'issue_date'   => Carbon::create(2026, 6, 1),
            'due_date'     => Carbon::create(2026, 6, 10),
            'period_start' => Carbon::create(2026, 6, 1),
            'period_end'   => Carbon::create(2026, 6, 30),
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ], $invoiceOverrides));

        return compact('tenant', 'router', 'customer', 'invoice');
    }

    private function runReminders(): array
    {
        return app(PaymentReminderService::class)->sendDueReminders();
    }

    #[Test]
    public function it_sends_a_reminder_on_the_configured_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 9, 0, 0));
        ['customer' => $customer, 'invoice' => $invoice] = $this->scenario(reminderDay: 5);

        $stats = $this->runReminders();

        $this->assertSame(1, $stats['reminded']);
        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($customer->email));
        $this->assertNotNull($invoice->fresh()->last_reminder_sent);
    }

    #[Test]
    public function it_does_not_send_before_the_configured_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 3, 9, 0, 0));
        $this->scenario(reminderDay: 5);

        $stats = $this->runReminders();

        $this->assertSame(0, $stats['reminded']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_is_idempotent_within_the_same_billing_cycle(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 9, 0, 0));
        $this->scenario(reminderDay: 5);

        $first  = $this->runReminders();
        $second = $this->runReminders();

        $this->assertSame(1, $first['reminded']);
        $this->assertSame(0, $second['reminded']);
        Mail::assertSent(PaymentReminderMail::class, 1);
    }

    #[Test]
    public function it_skips_paid_invoices(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 9, 0, 0));
        $this->scenario(reminderDay: 5, invoiceOverrides: [
            'status'      => 'paid',
            'balance_due' => 0,
        ]);

        $stats = $this->runReminders();

        $this->assertSame(0, $stats['reminded']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function reminder_day_31_fires_on_feb_28_in_a_non_leap_year(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 2, 28, 9, 0, 0));
        $this->scenario(reminderDay: 31, invoiceOverrides: [
            'period_start' => Carbon::create(2026, 2, 1),
            'period_end'   => Carbon::create(2026, 2, 28),
        ]);

        $stats = $this->runReminders();

        $this->assertSame(1, $stats['reminded']);
        Mail::assertSent(PaymentReminderMail::class, 1);
    }
}
