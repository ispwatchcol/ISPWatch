<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDocumentTemplateRequest;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContractNumberService;
use App\Services\Templates\AdvancedTemplateSanitizer;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSanitizer;
use Illuminate\Http\Request;

/**
 * CRUD for per-tenant document templates (factura, contrato, instalación).
 * Gated by the 'permission:manage_document_templates' route middleware —
 * deliberately separate from 'manage_tenant' (see routes/api.php for why).
 */
class DocumentTemplateController extends Controller
{
    protected TemplateSanitizer $sanitizer;
    protected AdvancedTemplateSanitizer $advancedSanitizer;
    protected TemplateRenderer $templateRenderer;

    public function __construct(
        TemplateSanitizer $sanitizer,
        AdvancedTemplateSanitizer $advancedSanitizer,
        TemplateRenderer $templateRenderer
    ) {
        $this->sanitizer = $sanitizer;
        $this->advancedSanitizer = $advancedSanitizer;
        $this->templateRenderer = $templateRenderer;
    }

    /**
     * One row per document type: whether the tenant has customized it, its
     * current draft (if any) and whether it's active.
     */
    public function index(Request $request)
    {
        $tenantId = $this->authTenant($request);

        $rows = DocumentTemplate::where('tenant_id', $tenantId)->get()->keyBy('type');

        $data = collect(DocumentTemplate::TYPES)->map(function (string $type) use ($rows) {
            $row = $rows->get($type);

            return [
                'type'             => $type,
                'body_html'        => $row?->body_html,
                'is_active'        => (bool) ($row?->is_active),
                'is_advanced_mode' => (bool) ($row?->is_advanced_mode),
                'has_draft'        => $row !== null,
                'updated_at'       => $row?->updated_at,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Detail for a single type, plus the closed placeholder whitelist the
     * editor should offer (config/document_placeholders.php — the same
     * source of truth PlaceholderResolver uses).
     */
    public function show(Request $request, string $type)
    {
        $this->assertValidType($type);
        $tenantId = $this->authTenant($request);

        $row = DocumentTemplate::where('tenant_id', $tenantId)->where('type', $type)->first();

        return response()->json([
            'type'               => $type,
            'body_html'          => $row?->body_html,
            'is_active'          => (bool) ($row?->is_active),
            'is_advanced_mode'   => (bool) ($row?->is_advanced_mode),
            'has_draft'          => $row !== null,
            'updated_at'         => $row?->updated_at,
            'placeholders'       => config("document_placeholders.{$type}", []),
            'block_placeholders' => config("document_placeholder_blocks.{$type}", []),
        ]);
    }

    /**
     * Upsert the tenant's draft for a type and activate it. body_html is
     * sanitized here (save time) and again at render time in
     * TemplateRenderer (defense in depth) — never trusted as-is, never
     * compiled as Blade. is_advanced_mode decide CUÁL sanitizer se usa:
     * TemplateSanitizer (allowlist acotado, shell fijo) o
     * AdvancedTemplateSanitizer (documento completo, sin shell) — nunca se
     * guarda HTML saneado por el sanitizer "equivocado" para el modo.
     */
    public function update(UpdateDocumentTemplateRequest $request, string $type)
    {
        $this->assertValidType($type);
        $tenantId = $this->authTenant($request);

        $isAdvancedMode = (bool) ($request->validated()['is_advanced_mode'] ?? false);
        $sanitized = $isAdvancedMode
            ? $this->advancedSanitizer->sanitize($request->validated()['body_html'])
            : $this->sanitizer->sanitize($request->validated()['body_html']);

        $row = DocumentTemplate::updateOrCreate(
            ['tenant_id' => $tenantId, 'type' => $type],
            [
                'body_html'        => $sanitized,
                'is_active'        => true,
                'is_advanced_mode' => $isAdvancedMode,
                'updated_by'       => $request->user()->id,
            ]
        );

        return response()->json([
            'message' => 'Plantilla guardada y activada correctamente.',
            'data'    => [
                'type'             => $type,
                'body_html'        => $row->body_html,
                'is_active'        => $row->is_active,
                'is_advanced_mode' => $row->is_advanced_mode,
                'has_draft'        => true,
                'updated_at'       => $row->updated_at,
            ],
        ]);
    }

    /**
     * "Restaurar plantilla base": deactivates the row without deleting the
     * draft, so it can be reactivated later. No-op (still 200) if the
     * tenant never customized this type — it's already on the base.
     */
    public function reset(Request $request, string $type)
    {
        $this->assertValidType($type);
        $tenantId = $this->authTenant($request);

        $row = DocumentTemplate::where('tenant_id', $tenantId)->where('type', $type)->first();
        $row?->update(['is_active' => false, 'updated_by' => $request->user()->id]);

        return response()->json([
            'message' => 'Plantilla restaurada a la base del sistema.',
            'data'    => [
                'type'      => $type,
                'is_active' => false,
                'has_draft' => $row !== null,
            ],
        ]);
    }

    /**
     * Renders an unsaved draft (request body, not persisted) against sample
     * data for the tenant's own branding — never against a real
     * customer/invoice, so nobody's data leaks into a live-typing preview
     * and it works identically for a brand new tenant with zero records.
     * Always uses the custom shell, regardless of is_active.
     *
     * If any block placeholder (config/document_placeholder_blocks.php)
     * couldn't be inserted — e.g. the tenant pasted it inside an HTML
     * attribute — TemplateRenderer::lastRenderWarnings() reports it here, and
     * it's surfaced as an X-Template-Warnings response header alongside the
     * PDF stream (informational only: the PDF still renders, without that
     * block's content, matching the "never break the render" rule already in
     * place for every other placeholder failure mode).
     */
    public function preview(UpdateDocumentTemplateRequest $request, string $type)
    {
        $this->assertValidType($type);
        $tenantId = $this->authTenant($request);
        $tenant = Tenant::findOrFail($tenantId);
        $draftHtml = $request->validated()['body_html'];
        // Refleja el modo que el tenant tiene seleccionado AHORA en el
        // editor, no lo persistido — puede estar probando un cambio de modo
        // antes de guardar.
        $isAdvancedMode = (bool) ($request->validated()['is_advanced_mode'] ?? false);

        $pdf = match ($type) {
            DocumentTemplate::TYPE_INVOICE => $this->templateRenderer->previewInvoice(
                $this->sampleInvoice($tenant),
                $draftHtml,
                $isAdvancedMode
            ),
            DocumentTemplate::TYPE_CONTRACT => $this->templateRenderer->previewContract(
                $this->sampleCustomer(),
                $this->sampleProfile(),
                $tenant,
                $this->samplePlan($tenant),
                '',
                now()->format('d/m/Y'),
                $draftHtml,
                $isAdvancedMode,
                // La vista previa muestra el consecutivo que le tocaría al
                // próximo contrato, pero NO lo reserva: previsualizar no puede
                // gastar números de la secuencia.
                ContractNumberService::format($tenant->contract_prefix, (int) ($tenant->next_contract_number ?: 1))
            ),
            DocumentTemplate::TYPE_INSTALLATION => $this->templateRenderer->previewInstallationSheet(
                $this->sampleInstallation($tenant),
                $this->sampleCustomer(),
                $this->sampleProfile(),
                null,
                $tenant,
                null,
                null,
                null,
                $this->samplePlan($tenant),
                '',
                null,
                now()->format('d/m/Y H:i'),
                $draftHtml,
                $isAdvancedMode
            ),
        };

        $response = $pdf->stream('vista-previa.pdf');

        $warnings = $this->buildTemplateWarnings($type);
        if (!empty($warnings)) {
            $response->headers->set('X-Template-Warnings', json_encode($warnings));
        }

        return $response;
    }

    /**
     * @return array<int,array{token:string,label:string}>
     */
    private function buildTemplateWarnings(string $type): array
    {
        $labels = config("document_placeholder_blocks.{$type}", []);

        return collect($this->templateRenderer->lastRenderWarnings())
            ->unique()
            ->map(fn (string $token) => [
                'token' => $token,
                'label' => $labels[$token] ?? $token,
            ])
            ->values()
            ->all();
    }

    private function authTenant(Request $request): int
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        return $tenantId;
    }

    private function assertValidType(string $type): void
    {
        abort_unless(in_array($type, DocumentTemplate::TYPES, true), 404, 'Tipo de documento no válido.');
    }

    private function sampleCustomer(): User
    {
        $customer = new User([
            'user_name'     => 'Cliente',
            'user_lastname' => 'de Ejemplo',
            'email'         => 'cliente@ejemplo.com',
            'tel'           => '3000000000',
        ]);
        // El nombre derivado (user_name + user_lastname) solo se calcula en
        // el hook `saving` del modelo; esta instancia nunca se guarda.
        $customer->name = 'Cliente de Ejemplo';

        return $customer;
    }

    private function sampleProfile(): CustomerProfile
    {
        return new CustomerProfile([
            'cedula'  => '000000000',
            'address' => 'Calle de Ejemplo # 1-23',
            'city'    => 'Ciudad de Ejemplo',
            'ip_user' => '10.0.0.1',
        ]);
    }

    private function samplePlan(Tenant $tenant): Plan
    {
        return new Plan([
            'tenant_id'    => $tenant->id,
            'name'         => 'Plan de Ejemplo 100MB',
            'speed_down'   => '100',
            'speed_up'     => '50',
            'cost_product' => 80000,
        ]);
    }

    private function sampleInvoice(Tenant $tenant): Invoice
    {
        $customer = $this->sampleCustomer();
        $customer->setRelation('customerProfile', $this->sampleProfile());

        $invoice = new Invoice([
            'tenant_id'    => $tenant->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'number'       => 'EJEMPLO-0001',
            'issue_date'   => now(),
            'due_date'     => now()->addDays(15),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'currency'     => $tenant->currency ?: 'COP',
            'subtotal'     => 100000,
            'tax'          => 0,
            'total'        => 100000,
            'balance_due'  => 100000,
            'status'       => 'issued',
            'notes'        => 'Esta es una factura de ejemplo para previsualizar la plantilla.',
        ]);
        $invoice->setRelation('tenant', $tenant);
        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('items', collect([
            new InvoiceItem([
                'description' => 'Plan de Internet de Ejemplo',
                'quantity'    => 1,
                'unit'        => null,
                'unit_price'  => 100000,
                'amount'      => 100000,
            ]),
        ]));

        return $invoice;
    }

    private function sampleInstallation(Tenant $tenant): CustomerInstallation
    {
        $installation = new CustomerInstallation([
            'tenant_id' => $tenant->id,
            'address'   => 'Calle de Ejemplo # 1-23',
            'equipment' => 'Router + ONU de ejemplo',
            'notes'     => 'Esta es una orden de ejemplo para previsualizar la plantilla.',
            'status'    => 'pendiente',
        ]);
        $installation->id = 0;

        return $installation;
    }
}
