<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CutType;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OverdueSuspensionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the cut no-show detector: a 'Corte Automático' router that can never
 * cut (no cut_day) or that left overdue customers connected after its cut
 * moment must be flagged — the same blind spot the billing no-show closes.
 */
class VerifyAutomaticCutsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeRouter(Tenant $tenant, ?int $cutDay, int $threshold = 1, string $cutTime = '00:00:00', string $cutTypeName = 'Corte Automático'): Router
    {
        $billing = Billing::create([
            'cut_day'          => $cutDay !== null ? Carbon::create(2026, 1, $cutDay)->toDateString() : null,
            'cut_time'         => $cutTime,
            'overdue_invoices' => $threshold,
            'status'           => 'pending',
        ]);

        $cutType = CutType::firstOrCreate(['name' => $cutTypeName]);

        return Router::create([
            'name'              => 'Router ' . uniqid(),
            'tenant_id'         => $tenant->id,
            'cut_type_id'       => $cutType->id,
            'billing_router_id' => $billing->id,
            'status'            => 'active',
        ]);
    }

    private function makeOverdueCustomer(Tenant $tenant, Router $router, int $overdueQty): void
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'   => $user->id,
            'name'      => "Cliente{$this->seq}",
            'last_name' => "Apellido{$this->seq}",
            'router_id' => $router->id,
            'status'    => true, // active (still connected)
        ]);

        for ($i = 0; $i < $overdueQty; $i++) {
            Invoice::create([
                'tenant_id'    => $tenant->id,
                'customer_id'  => $user->id,
                'number'       => uniqid('INV-'),
                'issue_date'   => now()->subDays(30),
                'due_date'     => now()->subDays(10),
                'period_start' => now()->subMonth()->startOfMonth(),
                'period_end'   => now()->subMonth()->endOfMonth(),
                'subtotal'     => 25000,
                'total'        => 25000,
                'balance_due'  => 25000,
                'status'       => 'overdue',
            ]);
        }
    }

    private function statusFor(array $rows, int $routerId): string
    {
        foreach ($rows as $r) {
            if ($r['router_id'] === $routerId) {
                return $r['status'];
            }
        }
        $this->fail("No audit row for router {$routerId}");
    }

    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function an_auto_router_without_a_cut_day_is_not_a_problem(): void
    {
        // No cut_day = automatic cut intentionally off → reported as no_cut_day,
        // never alerted.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant, cutDay: null);

        $rows = app(OverdueSuspensionService::class)->auditAutomaticCuts();

        $this->assertSame('no_cut_day', $this->statusFor($rows, $router->id));

        // And it must NOT make the command fail.
        $this->artisan('billing:verify-cuts', ['--no-mail' => true])
            ->assertExitCode(0);
    }

    #[Test]
    public function flags_cut_failing_when_overdue_customers_remain_connected_after_cut_day(): void
    {
        // Cut day was the 10th; today is the 15th and the customer (≥1 overdue)
        // is still active → the cut did not happen.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant, cutDay: 10, threshold: 1);
        $this->makeOverdueCustomer($tenant, $router, overdueQty: 1);

        $rows = app(OverdueSuspensionService::class)->auditAutomaticCuts();

        $this->assertSame('cut_failing', $this->statusFor($rows, $router->id));
    }

    #[Test]
    public function reports_pending_before_the_cut_day(): void
    {
        // Cut day is the 20th; today is the 15th → not due yet, no alarm.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant, cutDay: 20, threshold: 1);
        $this->makeOverdueCustomer($tenant, $router, overdueQty: 1);

        $rows = app(OverdueSuspensionService::class)->auditAutomaticCuts();

        $this->assertSame('pending', $this->statusFor($rows, $router->id));
    }

    #[Test]
    public function reports_ok_when_no_overdue_customers_remain(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant, cutDay: 10, threshold: 1);
        // No overdue customers on this router → nothing to cut.

        $rows = app(OverdueSuspensionService::class)->auditAutomaticCuts();

        $this->assertSame('ok', $this->statusFor($rows, $router->id));
    }

    #[Test]
    public function the_command_exits_nonzero_when_a_cut_is_failing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant, cutDay: 10, threshold: 1);
        $this->makeOverdueCustomer($tenant, $router, overdueQty: 1);

        $this->artisan('billing:verify-cuts', ['--no-mail' => true])
            ->assertExitCode(1);
    }

    #[Test]
    public function the_command_exits_zero_when_cuts_are_healthy(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $this->makeRouter($tenant, cutDay: 10, threshold: 1);
        // No overdue customers → ok.

        $this->artisan('billing:verify-cuts', ['--no-mail' => true])
            ->assertExitCode(0);
    }
}
