<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReminderMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentReminderController extends Controller
{
    protected $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Send a payment reminder for a specific invoice
     */
    public function sendReminder(Request $request, $invoiceId)
    {
        $invoice = Invoice::with(['customer.customerProfile', 'tenant'])->findOrFail($invoiceId);

        // Get customer data
        $customer = $invoice->customer;
        $profile = $customer->customerProfile;

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado para esta factura.'
            ], 404);
        }

        // Prepare invoice data
        $customerName = $profile
            ? trim("{$profile->name} {$profile->last_name}")
            : ($customer->user_name ?? $customer->name ?? 'Cliente');

        $data = [
            'customer_name' => $customerName,
            'invoice_number' => $invoice->number,
            'amount' => $invoice->balance_due ?? $invoice->total,
            'due_date' => $invoice->due_date,
            'company_name' => $invoice->tenant?->name ?? 'ISPWatch',
            'is_overdue' => $invoice->status === 'overdue'
        ];

        // Determine notification type from customer's router billing settings
        $notificationType = $this->getCustomerNotificationType($customer);

        $results = [
            'email' => null,
            'whatsapp' => null
        ];

        // Send Email
        if (in_array($notificationType, ['email', 'both'])) {
            $results['email'] = $this->sendEmailReminder($customer->email, $data);
        }

        // Send WhatsApp
        if (in_array($notificationType, ['whatsapp', 'both'])) {
            $phone = $profile?->phone ?? $customer->tel;
            if ($phone) {
                $results['whatsapp'] = $this->sendWhatsAppReminder($phone, $data);
            } else {
                $results['whatsapp'] = [
                    'success' => false,
                    'error' => 'No hay número de teléfono registrado para este cliente'
                ];
            }
        }

        // Update invoice last_reminder_sent
        $invoice->update(['last_reminder_sent' => now()]);

        // Determine overall success
        $anySuccess =
            ($results['email']['success'] ?? false) ||
            ($results['whatsapp']['success'] ?? false);

        return response()->json([
            'success' => $anySuccess,
            'message' => $anySuccess ? 'Recordatorio enviado exitosamente' : 'Error al enviar recordatorio',
            'notification_type' => $notificationType,
            'results' => $results
        ], $anySuccess ? 200 : 500);
    }

    /**
     * Send bulk reminders for pending/overdue invoices
     */
    public function sendBulkReminders(Request $request)
    {
        $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'exists:invoices,id'
        ]);

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($request->invoice_ids as $invoiceId) {
            try {
                $response = $this->sendReminder($request, $invoiceId);
                $responseData = json_decode($response->getContent(), true);

                if ($responseData['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                }

                $results[$invoiceId] = $responseData;
            } catch (\Exception $e) {
                Log::error('Bulk reminder failed', [
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage()
                ]);
                $failCount++;
                $results[$invoiceId] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => $successCount > 0,
            'message' => "Recordatorios enviados: {$successCount} exitosos, {$failCount} fallidos",
            'summary' => [
                'total' => count($request->invoice_ids),
                'success' => $successCount,
                'failed' => $failCount
            ],
            'results' => $results
        ]);
    }

    /**
     * Get the notification type configured for a customer's router
     */
    private function getCustomerNotificationType(User $customer): string
    {
        // Get customer's router billing configuration
        $billing = DB::table('customer_profile')
            ->join('router', 'customer_profile.router_id', '=', 'router.id')
            ->join('billing', 'router.billing_router_id', '=', 'billing.id')
            ->where('customer_profile.user_id', $customer->id)
            ->select('billing.notification_type')
            ->first();

        return $billing?->notification_type ?? 'email';
    }

    /**
     * Send email reminder
     */
    private function sendEmailReminder(string $email, array $data): array
    {
        try {
            Mail::to($email)->send(new PaymentReminderMail($data));

            Log::info('Payment reminder email sent', [
                'email' => $email,
                'invoice' => $data['invoice_number']
            ]);

            return [
                'success' => true,
                'message' => 'Email enviado correctamente'
            ];
        } catch (\Exception $e) {
            Log::error('Payment reminder email failed', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send WhatsApp reminder
     */
    private function sendWhatsAppReminder(string $phone, array $data): array
    {
        if (!$this->whatsAppService->isConfigured()) {
            return [
                'success' => false,
                'error' => 'WhatsApp no está configurado. Configura las credenciales en .env'
            ];
        }

        return $this->whatsAppService->sendPaymentReminder($phone, $data);
    }

    /**
     * Check WhatsApp configuration status
     */
    public function checkWhatsAppStatus()
    {
        return response()->json([
            'configured' => $this->whatsAppService->isConfigured()
        ]);
    }
}
