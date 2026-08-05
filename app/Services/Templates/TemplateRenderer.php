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
 *   - row with is_active = true, is_advanced_mode = false
 *                                                   => shell fijo + placeholders
 *                                                      (compile(), sin cambios).
 *   - row with is_active = true, is_advanced_mode = true
 *                                                   => documento HTML completo
 *                                                      del tenant, saneado por
 *                                                      AdvancedTemplateSanitizer,
 *                                                      Pdf::loadHTML() directo,
 *                                                      SIN el shell fijo
 *                                                      (compileAdvanced()).
 *   - row with is_active = false                   => legacy/base blade,
 *                                                       the draft in body_html
 *                                                       is left untouched so
 *                                                       it can be reactivated.
 *
 * compile() pipeline (modo seguro, shell fijo):
 *   A. sanitize the tenant's RAW stored body_html first (TemplateSanitizer,
 *      allowlist acotado) — un token de bloque como {{factura.tabla_items}}
 *      es solo texto inerte para HTMLPurifier en este punto.
 *   B. substitute scalar placeholders, HTML-escaped.
 *   C. block placeholders vía BlockMarkerInjector — marcador opaco + splice
 *      por DOM, nunca un reemplazo de string crudo de HTML de confianza.
 *
 * compileAdvanced() pipeline (modo avanzado, sin shell — auditoría 2026-08-01):
 *   A. AdvancedTemplateSanitizer::sanitizeParts() separa y sanea el bloque
 *      <style> (Filter.ExtractStyleBlocks + CSSTidy, sin url()/@import/
 *      expression()/behavior) y el body (allowlist amplio: div, table, img,
 *      h1-h6, etc.) del documento COMPLETO que guardó el tenant.
 *   B/C. Exactamente el mismo BlockMarkerInjector + PlaceholderResolver que
 *      el modo seguro, aplicados solo sobre el body ya saneado (nunca sobre
 *      el <style>, que no tiene placeholders) — mismo pipeline de
 *      cliente/empresa/servicio, factura.tabla_items, instalacion.fotos y
 *      firmas ya existente, sin duplicar lógica de resolución.
 *   Reensambla <html><head><style>...</style></head><body>...</body></html>
 *   y lo pasa a Pdf::loadHTML() directo — nunca a un shell Blade.
 */
class TemplateRenderer
{
    /**
     * Block tokens que no se pudieron insertar en el último compile()/
     * compileAdvanced(). Ver documentación completa en lastRenderWarnings().
     *
     * @var string[]
     */
    private array $lastWarnings = [];

    public function __construct(
        private readonly PlaceholderResolver $resolver,
        private readonly TemplateSanitizer $sanitizer,
        private readonly AdvancedTemplateSanitizer $advancedSanitizer,
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

        if ($template->is_advanced_mode) {
            return Pdf::loadHTML($this->compileAdvanced(
                $template->body_html,
                $this->resolver->forInvoice($invoice),
                $this->blockResolver->forInvoice($invoice),
                (int) $invoice->tenant_id,
                DocumentTemplate::TYPE_INVOICE
            ));
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

        $scalarValues = $this->resolver->forContract($customer, $profile, $tenant, $plan, $date);
        $blockValues = $this->blockResolver->forContract($tenant, $signature);

        if ($template->is_advanced_mode) {
            return Pdf::loadHTML($this->compileAdvanced(
                $template->body_html,
                $scalarValues,
                $blockValues,
                (int) $tenant->id,
                DocumentTemplate::TYPE_CONTRACT
            ));
        }

        // Modo seguro: la firma la sigue imprimiendo el shell fijo (fuera de
        // $body) — {{contrato.firma_cliente}} sólo importa en modo avanzado,
        // pero se resuelve igual aquí por si el tenant lo usa también.
        $body = $this->compile($template->body_html, $scalarValues, $blockValues, (int) $tenant->id, DocumentTemplate::TYPE_CONTRACT);

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

        $scalarValues = $this->resolver->forInstallation($installation, $customer, $profile, $prospect, $tenant, $technician, $date, $plan);
        $blockValues = $this->blockResolver->forInstallation($installation, $tenant, $photos, $customerSignature, $technicianSignature);

        if ($template->is_advanced_mode) {
            return Pdf::loadHTML($this->compileAdvanced(
                $template->body_html,
                $scalarValues,
                $blockValues,
                (int) $tenant->id,
                DocumentTemplate::TYPE_INSTALLATION
            ));
        }

        $body = $this->compile($template->body_html, $scalarValues, $blockValues, (int) $tenant->id, DocumentTemplate::TYPE_INSTALLATION);

        return Pdf::loadView('documents.shells.installation_shell', $legacyData + ['body' => $body]);
    }

    /**
     * Renders an UNSAVED draft body against the given invoice — always
     * through the custom shell (modo seguro) o Pdf::loadHTML directo (modo
     * avanzado), nunca la vista legacy. $isAdvancedMode refleja el modo que
     * el tenant tiene seleccionado AHORA en el editor (puede no coincidir
     * con lo persistido si todavía no guardó el cambio de modo).
     */
    public function previewInvoice(Invoice $invoice, string $draftHtml, bool $isAdvancedMode = false)
    {
        $scalarValues = $this->resolver->forInvoice($invoice);
        $blockValues = $this->blockResolver->forInvoice($invoice);

        if ($isAdvancedMode) {
            return Pdf::loadHTML($this->compileAdvanced($draftHtml, $scalarValues, $blockValues, (int) $invoice->tenant_id, DocumentTemplate::TYPE_INVOICE));
        }

        $body = $this->compile($draftHtml, $scalarValues, $blockValues, (int) $invoice->tenant_id, DocumentTemplate::TYPE_INVOICE);

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
        string $draftHtml,
        bool $isAdvancedMode = false
    ) {
        $scalarValues = $this->resolver->forContract($customer, $profile, $tenant, $plan, $date);
        $blockValues = $this->blockResolver->forContract($tenant, $signature);

        if ($isAdvancedMode) {
            return Pdf::loadHTML($this->compileAdvanced($draftHtml, $scalarValues, $blockValues, (int) $tenant->id, DocumentTemplate::TYPE_CONTRACT));
        }

        $body = $this->compile($draftHtml, $scalarValues, $blockValues, (int) $tenant->id, DocumentTemplate::TYPE_CONTRACT);

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
        string $draftHtml,
        bool $isAdvancedMode = false
    ) {
        $scalarValues = $this->resolver->forInstallation($installation, $customer, $profile, $prospect, $tenant, $technician, $date, $plan);
        $blockValues = $this->blockResolver->forInstallation($installation, $tenant, $photos, $customerSignature, $technicianSignature);

        if ($isAdvancedMode) {
            return Pdf::loadHTML($this->compileAdvanced($draftHtml, $scalarValues, $blockValues, (int) $tenant->id, DocumentTemplate::TYPE_INSTALLATION));
        }

        $body = $this->compile($draftHtml, $scalarValues, $blockValues, (int) $tenant->id, DocumentTemplate::TYPE_INSTALLATION);

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
     * Modo seguro: sanitiza con el allowlist acotado (TemplateSanitizer) y
     * embebe el resultado dentro del shell fijo.
     *
     * @param array<string,string> $scalarValues  {{namespace.campo}} => texto plano
     * @param array<string,string> $blockValues   {{namespace.campo}} => fragmento HTML de confianza
     */
    private function compile(string $html, array $scalarValues, array $blockValues, ?int $tenantId, string $documentType): string
    {
        $sanitized = $this->sanitizer->sanitize($html);

        [$marked, $markers] = $this->blockInjector->markify($sanitized, $blockValues);
        $withScalars = $this->resolver->apply($marked, $scalarValues);
        [$result, $this->lastWarnings] = $this->blockInjector->splice($withScalars, $markers, $tenantId, $documentType);

        return $result;
    }

    /**
     * Modo avanzado: sanitiza con el allowlist amplio (AdvancedTemplateSanitizer,
     * documento completo) y devuelve un documento HTML completo — nunca pasa
     * por un shell Blade, se entrega directo a Pdf::loadHTML().
     */
    private function compileAdvanced(string $html, array $scalarValues, array $blockValues, ?int $tenantId, string $documentType): string
    {
        ['body' => $body, 'style' => $style] = $this->advancedSanitizer->sanitizeParts($html);

        // Los placeholders solo tienen sentido dentro del body (nombres,
        // montos, tablas, fotos) — el <style> ya salió limpio de
        // Filter.ExtractStyleBlocks y no se vuelve a tocar.
        [$marked, $markers] = $this->blockInjector->markify($body, $blockValues);
        $withScalars = $this->resolver->apply($marked, $scalarValues);
        [$finalBody, $this->lastWarnings] = $this->blockInjector->splice($withScalars, $markers, $tenantId, $documentType);

        return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . ($style !== '' ? '<style>' . $style . '</style>' : '')
            . '</head><body>' . $finalBody . '</body></html>';
    }
}
