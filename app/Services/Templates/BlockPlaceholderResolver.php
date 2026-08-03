<?php

namespace App\Services\Templates;

use App\Models\CustomerInstallation;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the trusted, pre-rendered HTML fragments for block placeholders
 * (config/document_placeholder_blocks.php) — never sanitized, never built
 * from tenant-stored content. Consumed by TemplateRenderer::compile() and
 * spliced into the tenant's template by BlockMarkerInjector.
 *
 * Alcance V1 (auditoría 2026-07-31): solo los 4 bloques que de verdad
 * necesitan loop o <img>, que el allowlist del tenant no puede producir por
 * sí solo. Nada de contrato (la firma la sigue renderizando el shell fijo,
 * fuera de body_html) ni un bloque de totales (son placeholders escalares).
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
        ];
    }

    public function forInstallation(
        CustomerInstallation $installation,
        $photos,
        ?string $customerSignature,
        ?string $technicianSignature
    ): array {
        $tenantId = (int) $installation->tenant_id;

        return [
            'instalacion.fotos' => $this->safeRender(
                'documents.blocks.installation_photos',
                ['photos' => $photos],
                'instalacion.fotos',
                $tenantId
            ),
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
        ];
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
