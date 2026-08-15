<?php

namespace App\Services\Templates;

use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the trusted, pre-rendered HTML fragments for block placeholders
 * (config/document_placeholder_blocks.php) — never sanitized, never built
 * from tenant-stored content. Consumed by TemplateRenderer::compile() and
 * spliced into the tenant's template by BlockMarkerInjector.
 *
 * Alcance V1 (auditoría 2026-07-31): solo los bloques que de verdad
 * necesitan loop o <img>, que el allowlist del tenant no puede producir por
 * sí solo. Nada de un bloque de totales (son placeholders escalares).
 *
 * empresa.logo (auditoría 2026-08-03): agregado a los 3 tipos, incluido
 * contrato — la primera vez que contrato tiene un bloque. Misma resolución
 * de ruta LOCAL (no URL) que ya usa invoice_shell.blade.php: inmune a
 * enable_remote porque dompdf nunca hace un fetch de red.
 */
class BlockPlaceholderResolver
{
    public function forInvoice(Invoice $invoice): array
    {
        return [
            'factura.tabla_items' => $this->safeRender(
                'documents.blocks.invoice_items_table',
                ['invoice' => $invoice],
                'factura.tabla_items',
                (int) $invoice->tenant_id
            ),
            'empresa.logo' => $this->resolveLogo($invoice->tenant, 'empresa.logo', (int) $invoice->tenant_id),
        ];
    }

    /**
     * contrato.firma_cliente (auditoría 2026-08-04): en modo seguro la firma
     * la sigue imprimiendo el shell fijo (contract_shell.blade.php, fuera de
     * $body) sin necesidad de este bloque. Pero en modo avanzado NO hay
     * shell — compileAdvanced() sólo sustituye lo que esté en el HTML del
     * tenant — así que sin este bloque un contrato en modo avanzado no tenía
     * NINGUNA forma de mostrar la firma real del cliente, aunque
     * CustomerDocumentController::signContract() sí la capturaba y se la
     * pasaba a renderContract(); simplemente se perdía en silencio.
     */
    public function forContract(Tenant $tenant, ?string $signature = null, ?array $signatureAudit = null): array
    {
        return [
            'empresa.logo' => $this->resolveLogo($tenant, 'empresa.logo', (int) $tenant->id),
            'contrato.firma_cliente' => $this->safeRender(
                'documents.blocks.signature_image',
                ['signature' => $signature, 'alt' => 'Firma cliente'],
                'contrato.firma_cliente',
                (int) $tenant->id
            ),
            // En modo avanzado no hay shell donde imprimir la constancia de
            // firma remota, así que es la única vía para que el contrato del
            // tenant la lleve. Vacío en la firma presencial y en las vistas
            // previas: el bloque desaparece en vez de imprimir un recuadro con
            // guiones donde deberían ir la IP y la hora reales.
            'contrato.constancia_firma' => $signatureAudit ? $this->safeRender(
                'documents.blocks.signature_audit',
                ['audit' => $signatureAudit],
                'contrato.constancia_firma',
                (int) $tenant->id
            ) : '',
        ];
    }

    /**
     * El bloque `instalacion.fotos` se retiró el 2026-08-05: las fotos ya
     * viven en los documentos del cliente y en el PDF nunca llegaron a verse
     * (se referenciaban en disco local mientras se almacenan en S3).
     */
    public function forInstallation(
        CustomerInstallation $installation,
        Tenant $tenant,
        ?string $customerSignature,
        ?string $technicianSignature
    ): array {
        $tenantId = (int) $installation->tenant_id;

        return [
            'instalacion.firma_cliente' => $this->safeRender(
                'documents.blocks.signature_image',
                ['signature' => $customerSignature, 'alt' => 'Firma cliente'],
                'instalacion.firma_cliente',
                $tenantId
            ),
            'instalacion.firma_tecnico' => $this->safeRender(
                'documents.blocks.signature_image',
                ['signature' => $technicianSignature, 'alt' => 'Firma técnico'],
                'instalacion.firma_tecnico',
                $tenantId
            ),
            'empresa.logo' => $this->resolveLogo($tenant, 'empresa.logo', $tenantId),
        ];
    }

    /**
     * Ruta LOCAL en disco (no URL) al logo del tenant, mismo patrón ya
     * probado en documents/shells/invoice_shell.blade.php. file_exists() se
     * evalúa dentro del partial, igual que el resto de bloques de imagen —
     * degrada a vacío si no hay logo o falta el symlink storage:link.
     */
    private function resolveLogo(?Tenant $tenant, string $token, int $tenantId): string
    {
        if (!$tenant || !$tenant->logo) {
            return '';
        }

        // BlockMarkerInjector splice pasa este fragmento por un round-trip de
        // DOMDocument, cuyo serializador de libxml percent-codifica el
        // backslash en atributos URI (src/href/...) — inofensivo en
        // producción (Linux, siempre forward slash), pero rompería la ruta
        // en un dev local Windows si no se normaliza aquí.
        $logoPath = str_replace('\\', '/', public_path('storage/' . $tenant->logo));

        return $this->safeRender(
            'documents.blocks.logo',
            ['logoPath' => $logoPath],
            $token,
            $tenantId
        );
    }

    /**
     * A single block failing to render (ej. una foto con metadata corrupta)
     * nunca debe tumbar el documento completo — degrada a vacío y deja
     * rastro en logs, no silencia.
     */
    private function safeRender(string $view, array $data, string $token, int $tenantId): string
    {
        try {
            return view($view, $data)->render();
        } catch (Throwable $e) {
            Log::error("Bloque de plantilla '{$token}' falló al renderizar; se deja vacío.", [
                'tenant_id' => $tenantId,
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
