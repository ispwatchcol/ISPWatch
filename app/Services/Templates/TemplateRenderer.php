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
 * Single entry point for generating the 3 document PDFs. Wired into
 * BillingController::downloadPdf, CustomerDocumentController::signContract
 * and CustomerInstallationController::renderSheetPdf (Fase 2).
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
 *
 * compile() pipeline (Fase 1 de "placeholders de bloque", 2026-07-31):
 *   A. sanitize the tenant's RAW stored body_html first — a block token like
 *      {{factura.tabla_items}} is just inert text to HTMLPurifier at this
 *      point, same as any other word, never a privileged injection point.
 *   B. substitute scalar placeholders, HTML-escaped — safe in both content
 *      and attribute position, so no DOM-awareness is needed for scalars.
 *      No second full-document sanitize pass runs after this: escaping at
 *      substitution time gives the same guarantee more precisely, and a
 *      second sanitize pass would corrupt the trusted block HTML from step C
 *      (e.g. <img> for photos/signatures, which the tenant-facing allowlist
 *      forbids on purpose).
 *   C. block placeholders (factura.tabla_items, instalacion.fotos,
 *      instalacion.firma_cliente, instalacion.firma_tecnico) are substituted
 *      via App\Services\Templates\BlockMarkerInjector — opaque per-token
 *      marker + DOM-based splice, never a raw string-replace of trusted HTML.
 *      Skipped entirely (cheap no-op) when the tenant's template uses none.
 */
class TemplateRenderer
{
    /**
     * Block tokens that never made it into the document body on the most
     * recent compile() call (attribute position, or otherwise unreachable —
     * see BlockMarkerInjector). Read by DocumentTemplateController::preview()
     * right after a preview*() call to surface an explicit warning instead of
     * silently rendering the document without that content. Not meaningful
     * for the production render*() paths (nobody reads it there) — kept as
     * a getter rather than a return-value change so BillingController,
     * CustomerDocumentController and CustomerInstallationController don't
     * need to change at all.
     *
     * @var string[]
     */
    private array $lastWarnings = [];

    public function __construct(
        private readonly PlaceholderResolver $resolver,
        private readonly TemplateSanitizer $sanitizer,
        private readonly BlockPlaceholderResolver $blockResolver,
        private readonly BlockMarkerInjector $blockInjector,
    ) {
    }

    /** @return string[] block tokens que no se pudieron insertar en el último compile(). */
    public function lastRenderWarnings(): array
    {
        return $this->lastWarnings;
    }

    public function renderInvoice(Invoice $invoice)
    {
        $template = $this->activeTemplate((int) $invoice->tenant_id, DocumentTemplate::TYPE_INVOICE);

        if (!$template) {
            return Pdf::loadView('billing.invoice_pdf', ['invoice' => $invoice]);
        }

        $body = $this->compile(
            $template->body_html,
            $this->resolver->forInvoice($invoice),
            $this->blockResolver->forInvoice($invoice),
            (int) $invoice->tenant_id,
            DocumentTemplate::TYPE_INVOICE
        );

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

        // No block placeholders for contract in this scope: the signature
        // image is rendered by the shell itself, outside body_html, and the
        // additional-clauses text has no repeating/image content to justify one.
        $body = $this->compile(
            $template->body_html,
            $this->resolver->forContract($customer, $profile, $tenant, $plan, $date),
            [],
            (int) $tenant->id,
            DocumentTemplate::TYPE_CONTRACT
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
            $this->resolver->forInstallation($installation, $customer, $profile, $prospect, $tenant, $technician, $date, $plan),
            $this->blockResolver->forInstallation($installation, $photos, $customerSignature, $technicianSignature),
            (int) $tenant->id,
            DocumentTemplate::TYPE_INSTALLATION
        );

        return Pdf::loadView('documents.shells.installation_shell', $legacyData + ['body' => $body]);
    }

    /**
     * Renders an UNSAVED draft body against the given invoice — always
     * through the custom shell, never the legacy view, regardless of
     * whether the tenant has an active/inactive/no template row. Used by
     * DocumentTemplateController::preview() so a tenant can see their edits
     * before saving.
     */
    public function previewInvoice(Invoice $invoice, string $draftHtml)
    {
        $body = $this->compile(
            $draftHtml,
            $this->resolver->forInvoice($invoice),
            $this->blockResolver->forInvoice($invoice),
            (int) $invoice->tenant_id,
            DocumentTemplate::TYPE_INVOICE
        );

        return Pdf::loadView('documents.shells.invoice_shell', [
            'invoice' => $invoice,
            'tenant'  => $invoice->tenant,
            'body'    => $body,
        ]);
    }

    public function previewContract(
        User $customer,
        ?CustomerProfile $profile,
        Tenant $tenant,
        ?Plan $plan,
        string $signature,
        string $date,
        string $draftHtml
    ) {
        $body = $this->compile(
            $draftHtml,
            $this->resolver->forContract($customer, $profile, $tenant, $plan, $date),
            [],
            (int) $tenant->id,
            DocumentTemplate::TYPE_CONTRACT
        );

        return Pdf::loadView('documents.shells.contract_shell', [
            'customer'  => $customer,
            'profile'   => $profile,
            'tenant'    => $tenant,
            'plan'      => $plan,
            'signature' => $signature,
            'date'      => $date,
            'body'      => $body,
        ]);
    }

    public function previewInstallationSheet(
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
        string $date,
        string $draftHtml
    ) {
        $body = $this->compile(
            $draftHtml,
            $this->resolver->forInstallation($installation, $customer, $profile, $prospect, $tenant, $technician, $date, $plan),
            $this->blockResolver->forInstallation($installation, $photos, $customerSignature, $technicianSignature),
            (int) $tenant->id,
            DocumentTemplate::TYPE_INSTALLATION
        );

        return Pdf::loadView('documents.shells.installation_shell', [
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
            'body'                 => $body,
        ]);
    }

    private function activeTemplate(int $tenantId, string $type): ?DocumentTemplate
    {
        return DocumentTemplate::where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param array<string,string> $scalarValues  {{namespace.campo}} => texto plano
     * @param array<string,string> $blockValues   {{namespace.campo}} => fragmento HTML de confianza
     */
    private function compile(string $html, array $scalarValues, array $blockValues, ?int $tenantId, string $documentType): string
    {
        $sanitized = $this->sanitizer->sanitize($html);

        // Block tokens MUST become opaque markers before scalar substitution
        // runs — PlaceholderResolver::apply() blanks any {{...}} it doesn't
        // recognize as a scalar value, which would otherwise wipe out block
        // tokens before BlockMarkerInjector ever saw them.
        [$marked, $markers] = $this->blockInjector->markify($sanitized, $blockValues);

        $withScalars = $this->resolver->apply($marked, $scalarValues);

        [$result, $this->lastWarnings] = $this->blockInjector->splice($withScalars, $markers, $tenantId, $documentType);

        return $result;
    }
}
