<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $accessToken;
    protected $phoneNumberId;
    protected $apiVersion = 'v18.0';
    protected $baseUrl = 'https://graph.facebook.com';

    public function __construct()
    {
        $this->accessToken = config('services.whatsapp.access_token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
    }

    /**
     * Send a template message via WhatsApp Business API
     *
     * @param string $to Phone number with country code (e.g., 573001234567)
     * @param string $templateName Template name approved in Meta Business
     * @param array $components Template components (header, body, buttons)
     * @param string $language Template language code (default: es)
     * @return array
     */
    public function sendTemplateMessage(string $to, string $templateName, array $components = [], string $language = 'es'): array
    {
        if (!$this->accessToken || !$this->phoneNumberId) {
            Log::warning('WhatsApp API credentials not configured');
            return [
                'success' => false,
                'error' => 'WhatsApp API credentials not configured. Please set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID in .env'
            ];
        }

        // Normalize phone number (remove + and spaces)
        $to = preg_replace('/[^0-9]/', '', $to);

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $language
                ]
            ]
        ];

        // Add components if provided
        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('WhatsApp message sent', [
                    'to' => $to,
                    'template' => $templateName,
                    'message_id' => $response->json('messages.0.id')
                ]);

                return [
                    'success' => true,
                    'message_id' => $response->json('messages.0.id'),
                    'data' => $response->json()
                ];
            }

            Log::error('WhatsApp API error', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Unknown error',
                'code' => $response->json('error.code')
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send payment reminder via WhatsApp
     *
     * @param string $phoneNumber Customer phone number
     * @param array $data Invoice data (customer_name, invoice_number, amount, due_date, company_name)
     * @return array
     */
    public function sendPaymentReminder(string $phoneNumber, array $data): array
    {
        // Build template components with variables
        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $data['customer_name']],
                    ['type' => 'text', 'text' => $data['invoice_number']],
                    ['type' => 'text', 'text' => number_format($data['amount'], 0, ',', '.')],
                    ['type' => 'text', 'text' => $data['due_date']],
                    ['type' => 'text', 'text' => $data['company_name'] ?? 'ISPWatch'],
                ]
            ]
        ];

        return $this->sendTemplateMessage($phoneNumber, 'payment_reminder', $components);
    }

    /**
     * Send "invoice created" notification via WhatsApp
     *
     * @param string $phoneNumber Customer phone number
     * @param array $data Invoice data (customer_name, invoice_number, amount, due_date, company_name)
     * @return array
     */
    public function sendInvoiceCreated(string $phoneNumber, array $data): array
    {
        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $data['customer_name']],
                    ['type' => 'text', 'text' => $data['invoice_number']],
                    ['type' => 'text', 'text' => number_format($data['amount'], 0, ',', '.')],
                    ['type' => 'text', 'text' => $data['due_date']],
                    ['type' => 'text', 'text' => $data['company_name'] ?? 'ISPWatch'],
                ]
            ]
        ];

        return $this->sendTemplateMessage($phoneNumber, 'invoice_created', $components);
    }

    /**
     * Check if WhatsApp is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->accessToken) && !empty($this->phoneNumberId);
    }
}
