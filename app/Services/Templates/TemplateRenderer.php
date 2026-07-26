<?php

namespace App\Services\Templates;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Prospect;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Single entry point for generating the 3 document PDFs. Not yet wired into
 * BillingController / CustomerDocumentController / CustomerInstallationController
 * (Fase 2) — those still call Pdf::loadView(...) directly against the legacy
 * blades.
 *
 * Business rule for which template is used, per tenant + type:
 *   - no row in document_templates                => legacy/base blade.
 *   - row with is_active = true                    => tenant's custom body,
 *                                                       placeholders resolved
 *                                                       and sanitized.
 *   - row with is_active = false                   => legacy/base blade,
 *                                                       the draft in body_html
 *                                                       is left untouched so
 *                                                       it can be reactivated.
 *
 * The legacy blades (billing.invoice_pdf, documents.contract_pdf,
 * documents.installation_sheet_pdf) are kept as the fallback as a
 * transitional, zero-regression strategy for Fase 1 — not as the permanent
 * design. A future iteration should fold the "base" template into this same
 * pipeline (e.g. a system-seeded row) so there is a single render path.
 */
class TemplateRenderer
{
    public function __construct(
        private readonly PlaceholderResolver $resolver,
        private readonly TemplateSanitizer $sanitizer,
    ) {
    }

    public function renderInvoice(Invoice $invoice)
    {
        $template = $this->activeTemplate((int) $invoice->tenant_id, DocumentTemplate::TYPE_INVOICE);

        if (!$template) {
            return Pdf::loadView('billing.invoice_pdf', ['invoice' => $invoice]);
        }

        $body = $this->compile($template->body_html, $this->resolver->forInvoice($invoice));

        return Pdf::loadView('documents.shells.invoice_shell', [
            'invoice' => $invoice,
            'tenant'  => $invoice->tenant,
            'body'    => $body,
        ]);
    }

    public function renderContract(
        User $customer,
        ?CustomerProfile $profile,
        Tenant $tenant,
        ?Plan $plan,
        string $signature,
        string $date
    ) {
        $template = $this->activeTemplate((int) $tenant->id, DocumentTemplate::TYPE_CONTRACT);

        $legacyData = [
            'customer'  => $customer,
            'profile'   => $profile,
            'tenant'    => $tenant,
            'plan'      => $plan,
            'signature' => $signature,
            'date'      => $date,
        ];

        if (!$template) {
            return Pdf::loadView('documents.contract_pdf', $legacyData);
        }

        $body = $this->compile(
            $template->body_html,
            $this->resolver->forContract($customer, $profile, $tenant, $plan, $date)
        );

        return Pdf::loadView('documents.shells.contract_shell', $legacyData + ['body' => $body]);
    }

    public function renderInstallationSheet(
        CustomerInstallation $installation,
        ?User $customer,
        ?CustomerProfile $profile,
        ?Prospect $prospect,
        Tenant $tenant,
        ?User $technician,
        $photos,
        $sectorial,
        $router,
        ?Plan $plan,
        string $customerSignature,
        ?string $technicianSignature,
        string $date
    ) {
        $template = $this->activeTemplate((int) $tenant->id, DocumentTemplate::TYPE_INSTALLATION);

        $legacyData = [
            'installation'         => $installation,
            'customer'             => $customer,
            'profile'              => $profile,
            'prospect'             => $prospect,
            'tenant'               => $tenant,
            'technician'           => $technician,
            'photos'               => $photos,
            'sectorial'            => $sectorial,
            'router'               => $router,
            'plan'                 => $plan,
            'customer_signature'   => $customerSignature,
            'technician_signature' => $technicianSignature,
            'date'                 => $date,
        ];

        if (!$template) {
            return Pdf::loadView('documents.installation_sheet_pdf', $legacyData);
        }

        $body = $this->compile(
            $template->body_html,
            $this->resolver->forInstallation($installation, $customer, $profile, $prospect, $tenant, $technician, $date)
        );

        return Pdf::loadView('documents.shells.installation_shell', $legacyData + ['body' => $body]);
    }

    private function activeTemplate(int $tenantId, string $type): ?DocumentTemplate
    {
        return DocumentTemplate::where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();
    }

    private function compile(string $html, array $values): string
    {
        return $this->sanitizer->sanitize($this->resolver->apply($html, $values));
    }
}
