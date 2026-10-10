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

        // La comprobación va ANTES de tocar el perfil. Estaba después, y leer
        // `customerProfile` sobre null reventaba con 500 en vez de devolver el
        // 404 que este bloque pretendía. Era inalcanzable mientras
        // `invoices.customer_id` fuese NOT NULL con borrado en cascada: no
        // existía la factura sin titular. Desde P-43 sí existe — el histórico
        // sobrevive al cliente— y este camino se puede recorrer de verdad.
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Esta factura ya no tiene titular: el cliente fue dado de baja. '
                    . 'Se conserva por su valor contable, pero no hay a quién enviarle el recordatorio.',
            ], 404);
        }

        $profile = $customer->customerProfile;

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
     *
     * A diferencia del envío individual —donde un agente abre UNA factura y
     * decide sobre ESE caso—, aquí el operador marca casillas en el listado (o
     * «seleccionar todo») y dispara sobre el lote. No hay una decisión por
     * cliente, así que las preferencias del cliente SÍ mandan: quien pidió no
     * recibir avisos de factura no puede recibir uno porque su factura entró en
     * una selección masiva.
     *
     * El envío individual conserva su excepción a propósito (ver sendReminder).
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
        $skippedCount = 0;

        foreach ($request->invoice_ids as $invoiceId) {
            try {
                // Las preferencias se consultan JUSTO ANTES de enviar, sobre el
                // estado actual del cliente: entre que el operador marcó la
                // casilla y pulsó el botón, alguien pudo silenciarlo.
                if ($motivo = $this->reminderOptOutReason($invoiceId)) {
                    $skippedCount++;
                    $results[$invoiceId] = [
                        'success' => false,
                        'skipped' => true,
                        'reason'  => $motivo,
                        'message' => self::OPT_OUT_MESSAGES[$motivo],
                    ];

                    // Trazabilidad del omitido, sin datos de contacto: basta el
                    // id de la factura y el motivo normalizado para auditar por
                    // qué ese cliente no recibió nada.
                    Log::info('Bulk reminder skipped by customer preference', [
                        'invoice_id' => $invoiceId,
                        'reason'     => $motivo,
                    ]);

                    continue; // sin tocar last_reminder_sent: no se envió nada
                }

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

        $partes = ["{$successCount} exitosos"];
        if ($skippedCount > 0) {
            // Se nombra aparte de los fallidos: un omitido no es un error que
            // haya que ir a investigar, es la preferencia del cliente aplicada.
            $partes[] = "{$skippedCount} omitidos por preferencia del cliente";
        }
        $partes[] = "{$failCount} fallidos";

        return response()->json([
            // Nada que fallara no es un fracaso: si el lote entero eran clientes
            // silenciados, la operación hizo exactamente lo que debía.
            'success' => $successCount > 0 || ($failCount === 0 && $skippedCount > 0),
            'message' => 'Recordatorios enviados: ' . implode(', ', $partes),
            'summary' => [
                'total' => count($request->invoice_ids),
                'success' => $successCount,
                'skipped' => $skippedCount,
                'failed' => $failCount
            ],
            'results' => $results
        ]);
    }

    /** Motivos por los que un envío masivo se salta una factura. */
    private const OPT_OUT_MESSAGES = [
        'notify_invoice_disabled' => 'Omitido: el cliente pidió no recibir notificaciones de factura.',
        'excluded_from_billing'   => 'Omitido: el cliente está marcado como «no facturar».',
    ];

    /**
     * ¿Este cliente pidió que no se le avise? Devuelve el motivo normalizado, o
     * null si se le puede escribir.
     *
     * Mismas dos banderas que respetan los envíos automáticos
     * (BillingService::notifyInvoiceCreated y PaymentReminderService), para que
     * el lote no sea una puerta trasera a lo que el ciclo automático respeta.
     *
     * La factura se relee dentro del scope de tenant: un id de otra sede no
     * resuelve, y el envío propiamente dicho lo vuelve a comprobar.
     */
    private function reminderOptOutReason($invoiceId): ?string
    {
        $invoice = Invoice::with('customer.customerProfile')->find($invoiceId);
        $profile = $invoice?->customer?->customerProfile;

        if (!$profile) {
            return null; // sin perfil no hay preferencia que respetar
        }

        if ($profile->exclude_from_billing) {
            return 'excluded_from_billing';
        }

        if (!$profile->notify_invoice) {
            return 'notify_invoice_disabled';
        }

        return null;
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
