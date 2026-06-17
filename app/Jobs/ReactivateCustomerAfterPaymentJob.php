<?php

namespace App\Jobs;

use App\Services\BillingService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Intenta reconectar al cliente al router después de que un pago
 * liquidó su saldo vencido. Se despacha de forma asíncrona para que
 * el HTTP response del registro de pago no quede bloqueado por el SSH.
 */
class ReactivateCustomerAfterPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 90;

    public function __construct(public readonly int $customerId) {}

    public function handle(WhatsAppService $whatsApp): void
    {
        $service = new BillingService($whatsApp);
        $service->reactivateIfCleared($this->customerId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[ReactivateCustomerAfterPaymentJob] Falló reconexión post-pago para cliente {$this->customerId}: " . $e->getMessage());
    }
}
