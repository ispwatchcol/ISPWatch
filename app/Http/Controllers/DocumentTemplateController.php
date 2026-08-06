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
use App\Services\Templates\DocumentStarterLibrary;
use App\Services\Templates\PdfPageGeometry;
use App\Services\Templates\TemplateDiagnostics;
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
    protected TemplateDiagnostics $diagnostics;
    protected DocumentStarterLibrary $starters;
    protected PdfPageGeometry $geometry;

    public function __construct(
        TemplateSanitizer $sanitizer,
        AdvancedTemplateSanitizer $advancedSanitizer,
        TemplateRenderer $templateRenderer,
        TemplateDiagnostics $diagnostics,
        DocumentStarterLibrary $starters,
        PdfPageGeometry $geometry
    ) {
        $this->sanitizer = $sanitizer;
        $this->advancedSanitizer = $advancedSanitizer;
        $this->templateRenderer = $templateRenderer;
        $this->diagnostics = $diagnostics;
        $this->starters = $starters;
        $this->geometry = $geometry;
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
                'page_size'        => $row?->page_size ?: DocumentTemplate::DEFAULT_PAGE_SIZE,
                'page_orientation' => $row?->page_orientation ?: DocumentTemplate::DEFAULT_PAGE_ORIENTATION,
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
        $tenant = Tenant::find($tenantId);

        return response()->json([
            'type'               => $type,
            'body_html'          => $row?->body_html,
            'is_active'          => (bool) ($row?->is_active),
            'is_advanced_mode'   => (bool) ($row?->is_advanced_mode),
            'page_size'          => $row?->page_size ?: DocumentTemplate::DEFAULT_PAGE_SIZE,
            'page_orientation'   => $row?->page_orientation ?: DocumentTemplate::DEFAULT_PAGE_ORIENTATION,
            'has_draft'          => $row !== null,
            'updated_at'         => $row?->updated_at,
            'placeholders'       => config("document_placeholders.{$type}", []),
            'block_placeholders' => config("document_placeholder_blocks.{$type}", []),
            'page_sizes'         => DocumentTemplate::PAGE_SIZES,
            'page_orientations'  => DocumentTemplate::PAGE_ORIENTATIONS,
            // Geometría real de dompdf para las 6 combinaciones de hoja, y el
            // CSS con el que el editor visual imita sus defaults. El frontend
            // NO calcula ninguno de estos números: tenía sus propias
            // constantes copiadas a ojo y por eso dibujaba los cortes de
            // página fuera de sitio (ver App\Services\Templates\PdfPageGeometry).
            'page_metrics'       => $this->geometry->allMetrics(),
            'editor_base_css'    => $this->geometry->editorBaseCss(),
            // En modo seguro el fragmento no manda: lo envuelve el shell fijo
            // con SU tipografía. El editor tiene que mostrarlo con esa, no con
            // la del documento completo.
            'editor_fragment_css' => $this->geometry->editorFragmentCss($type),
            // Para que {{empresa.logo}} se vea como imagen dentro del editor y
            // no como texto: es el mismo archivo que BlockPlaceholderResolver
            // inserta en el PDF. null si el tenant todavía no subió logo, que
            // en el PDF significa exactamente "no sale nada".
            'logo_url'           => $tenant?->logo ? asset('storage/' . $tenant->logo) : null,
            // Sólo metadatos: los cuerpos pesan varios KB cada uno y esto se
            // pide en cada carga del editor. El cuerpo se baja aparte, cuando
            // el tenant elige una.
            'starters'           => $this->starters->listFor($type),
        ]);
    }

    /**
     * Cuerpo de una plantilla base, para cargarla en el editor. No persiste
     * nada: el tenant la recibe como borrador y decide si la guarda.
     */
    public function starter(Request $request, string $type, string $slug)
    {
        $this->assertValidType($type);
        $this->authTenant($request);

        $starter = $this->starters->find($type, $slug);
        abort_if($starter === null, 404, 'Plantilla base no encontrada.');

        return response()->json(['data' => $starter]);
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

        $validated = $request->validated();

        $isAdvancedMode = (bool) ($validated['is_advanced_mode'] ?? false);
        $sanitized = $isAdvancedMode
            ? $this->advancedSanitizer->sanitize($validated['body_html'])
            : $this->sanitizer->sanitize($validated['body_html']);

        $row = DocumentTemplate::updateOrCreate(
            ['tenant_id' => $tenantId, 'type' => $type],
            [
                'body_html'        => $sanitized,
                'is_active'        => true,
                'is_advanced_mode' => $isAdvancedMode,
                // 'sometimes' + 'nullable': la clave puede no venir (cliente
                // viejo) o venir null ("usa el default") — ambos caen al
                // default, nunca a un string vacío que rompería setPaper().
                'page_size'        => ($validated['page_size'] ?? null) ?: DocumentTemplate::DEFAULT_PAGE_SIZE,
                'page_orientation' => ($validated['page_orientation'] ?? null) ?: DocumentTemplate::DEFAULT_PAGE_ORIENTATION,
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
                'page_size'        => $row->page_size,
                'page_orientation' => $row->page_orientation,
                'has_draft'        => true,
                'updated_at'       => $row->updated_at,
            ],
            // Mismo diagnóstico que la vista previa, sobre el HTML tal como
            // lo escribió el tenant (antes de sanear). Va aquí además de en
            // preview() porque guardar sin previsualizar es un camino real:
            // sin esto, la plantilla queda ACTIVA y generando documentos con
            // los datos en blanco sin que nadie se entere.
            'warnings' => $this->diagnostics->inspect($validated['body_html'], $type, $isAdvancedMode),
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
     * Todo lo que pudo salir mal sin romper el render se devuelve en la
     * cabecera X-Template-Warnings junto al PDF (nunca como error: el PDF se
     * entrega igual, que es la regla de siempre). Dos fuentes, un solo canal:
     *   - bloques que no se pudieron insertar en su posición, que sólo se
     *     saben DESPUÉS de renderizar (TemplateRenderer::lastRenderWarnings()).
     *   - marcadores de otro sistema, de otro tipo de documento, con typos, e
     *     imágenes remotas — App\Services\Templates\TemplateDiagnostics, que
     *     inspecciona el borrador CRUDO.
     */
    public function preview(UpdateDocumentTemplateRequest $request, string $type)
    {
        $this->assertValidType($type);
        $tenantId = $this->authTenant($request);
        $tenant = Tenant::findOrFail($tenantId);
        $validated = $request->validated();
        $draftHtml = $validated['body_html'];
        // Refleja el modo y el papel que el tenant tiene seleccionados AHORA
        // en el editor, no lo persistido — puede estar probando un cambio
        // antes de guardar, y una vista previa que ignorara eso mostraría el
        // documento con la configuración vieja.
        $isAdvancedMode = (bool) ($validated['is_advanced_mode'] ?? false);
        $pageSize = ($validated['page_size'] ?? null) ?: DocumentTemplate::DEFAULT_PAGE_SIZE;
        $pageOrientation = ($validated['page_orientation'] ?? null) ?: DocumentTemplate::DEFAULT_PAGE_ORIENTATION;

        $pdf = match ($type) {
            DocumentTemplate::TYPE_INVOICE => $this->templateRenderer->previewInvoice(
                $this->sampleInvoice($tenant),
                $draftHtml,
                $isAdvancedMode,
                $pageSize,
                $pageOrientation
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
                ContractNumberService::format($tenant->contract_prefix, (int) ($tenant->next_contract_number ?: 1)),
                $pageSize,
                $pageOrientation
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
                $isAdvancedMode,
                $pageSize,
                $pageOrientation
            ),
        };

        $response = $pdf->stream('vista-previa.pdf');

        $warnings = $this->diagnostics->inspectWithOrphanedBlocks(
            $draftHtml,
            $type,
            $this->templateRenderer->lastRenderWarnings(),
            $isAdvancedMode
        );

        if (!empty($warnings)) {
            // json_encode SIN JSON_UNESCAPED_UNICODE a propósito: los mensajes
            // llevan tildes y comillas angulares, y una cabecera HTTP no es
            // UTF-8 (RFC 9110: ISO-8859-1 histórico). El escapado \uXXXX por
            // defecto deja la cabecera en ASCII puro y JSON.parse la devuelve
            // intacta en el navegador.
            $response->headers->set('X-Template-Warnings', json_encode($warnings));
        }

        return $response;
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
            'state'   => 'Departamento de Ejemplo',
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
