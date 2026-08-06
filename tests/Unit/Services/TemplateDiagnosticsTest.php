<?php

namespace Tests\Unit\Services;

use App\Services\Templates\TemplateDiagnostics;
use Tests\TestCase;

class TemplateDiagnosticsTest extends TestCase
{
    private TemplateDiagnostics $diagnostics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diagnostics = new TemplateDiagnostics();
    }

    /** @return array<string,array{kind:string,token:string,label:string,message:string}> */
    private function inspectByToken(string $html, string $type): array
    {
        return collect($this->diagnostics->inspect($html, $type))->keyBy('token')->all();
    }

    public function test_a_template_using_only_valid_placeholders_produces_no_findings(): void
    {
        $html = '<p>{{cliente.nombre}} {{cliente.apellido}} — {{plan.nombre}} — {{contrato.numero}}</p>'
            . '<div>{{empresa.logo}}</div>';

        $this->assertSame([], $this->diagnostics->inspect($html, 'contract'));
    }

    public function test_it_tolerates_spaces_inside_the_braces_like_the_resolver_does(): void
    {
        $this->assertSame([], $this->diagnostics->inspect('<p>{{ cliente.nombre }}</p>', 'contract'));
    }

    /**
     * El caso real del 2026-08-05: contrato CRC exportado de WispHub. Los 8
     * marcadores que se blanqueaban tienen que salir todos identificados.
     */
    public function test_it_maps_wisphub_placeholders_to_their_ispwatch_equivalent(): void
    {
        $html = '<p>{{ cliente_nombre }} {{ cliente_apellidos }} {{ cliente.user.email }}</p>'
            . '<p>{{ plan_internet.nombre }} {{ plan_internet.precio }} {{ fecha_instalacion }}</p>'
            . '<p>{{cliente.localidad}}</p>';

        $findings = $this->inspectByToken($html, 'contract');

        $expected = [
            'cliente_nombre'       => 'cliente.nombre',
            'cliente_apellidos'    => 'cliente.apellido',
            'cliente.user.email'   => 'cliente.email',
            'plan_internet.nombre' => 'plan.nombre',
            'plan_internet.precio' => 'plan.valor_mensual',
            'fecha_instalacion'    => 'contrato.fecha',
            'cliente.localidad'    => 'cliente.departamento',
        ];

        foreach ($expected as $foreign => $suggestion) {
            $this->assertArrayHasKey($foreign, $findings, "No se detectó {$foreign}");
            $this->assertSame(TemplateDiagnostics::KIND_FOREIGN_PLACEHOLDER, $findings[$foreign]['kind']);
            $this->assertStringContainsString('{{' . $suggestion . '}}', $findings[$foreign]['message']);
        }
    }

    /**
     * La misma etiqueta ajena no significa lo mismo en todos los tipos: en un
     * contrato 'fecha_instalacion' es la fecha de firma, en una hoja de
     * instalación es la fecha de la orden. Por eso la sugerencia depende del
     * tipo y no puede vivir en una sola tabla plana.
     */
    public function test_the_same_foreign_placeholder_suggests_a_different_token_per_document_type(): void
    {
        $contract = $this->inspectByToken('<p>{{fecha_instalacion}}</p>', 'contract');
        $installation = $this->inspectByToken('<p>{{fecha_instalacion}}</p>', 'installation');

        $this->assertStringContainsString('{{contrato.fecha}}', $contract['fecha_instalacion']['message']);
        $this->assertStringContainsString('{{instalacion.fecha}}', $installation['fecha_instalacion']['message']);
    }

    /**
     * P-3: copiar y pegar entre plantillas de distinto tipo. El token existe
     * y está bien escrito, sólo que en otro catálogo — el mensaje tiene que
     * decir eso y no "marcador desconocido", que mandaría a buscar un typo
     * que no existe.
     */
    public function test_it_reports_a_placeholder_that_belongs_to_another_document_type(): void
    {
        $findings = $this->inspectByToken('<p>Total: {{factura.total}}</p>', 'contract');

        $this->assertSame(TemplateDiagnostics::KIND_WRONG_TYPE, $findings['factura.total']['kind']);
        $this->assertStringContainsString('Factura', $findings['factura.total']['message']);
        $this->assertStringContainsString('Contrato', $findings['factura.total']['message']);
    }

    public function test_it_detects_a_block_placeholder_from_another_document_type(): void
    {
        $findings = $this->inspectByToken('<div>{{factura.tabla_items}}</div>', 'contract');

        $this->assertSame(TemplateDiagnostics::KIND_WRONG_TYPE, $findings['factura.tabla_items']['kind']);
    }

    public function test_it_suggests_the_closest_token_for_a_genuine_typo(): void
    {
        $findings = $this->inspectByToken('<p>{{cliente.telefno}}</p>', 'contract');

        $this->assertSame(TemplateDiagnostics::KIND_UNKNOWN_PLACEHOLDER, $findings['cliente.telefno']['kind']);
        $this->assertStringContainsString('{{cliente.telefono}}', $findings['cliente.telefno']['message']);
    }

    /**
     * Una sugerencia equivocada cuesta más que no sugerir: manda al tenant a
     * cambiar un marcador que estaba bien. Ante algo que no se parece a nada,
     * el mensaje remite al catálogo y no inventa un parecido.
     */
    public function test_it_does_not_invent_a_suggestion_for_something_that_resembles_nothing(): void
    {
        $findings = $this->inspectByToken('<p>{{zzz.qqq_wwww}}</p>', 'contract');

        $this->assertSame(TemplateDiagnostics::KIND_UNKNOWN_PLACEHOLDER, $findings['zzz.qqq_wwww']['kind']);
        $this->assertStringNotContainsString('¿Querías escribir', $findings['zzz.qqq_wwww']['message']);
    }

    /**
     * Estos no llevan llaves: para ISPwatch son texto y se imprimen tal cual
     * en el PDF. Por eso no los ve el escaneo de {{...}} y necesitan su
     * propia detección.
     */
    public function test_it_detects_foreign_markers_that_do_not_use_the_brace_syntax(): void
    {
        $html = '<p>Contrato No. CO-NUMERO_CONTRATO_TAG</p>'
            . '<img src="FIRMA_CLIENTE_NO_BORRAR" width="460" />';

        $findings = $this->inspectByToken($html, 'contract');

        $this->assertSame(TemplateDiagnostics::KIND_FOREIGN_MARKER, $findings['NUMERO_CONTRATO_TAG']['kind']);
        $this->assertStringContainsString('{{contrato.numero}}', $findings['NUMERO_CONTRATO_TAG']['message']);
        $this->assertStringContainsString('{{contrato.firma_cliente}}', $findings['FIRMA_CLIENTE_NO_BORRAR']['message']);
    }

    public function test_the_signature_marker_suggests_the_installation_token_on_an_installation_sheet(): void
    {
        $findings = $this->inspectByToken('<img src="FIRMA_CLIENTE_NO_BORRAR" />', 'installation');

        $this->assertStringContainsString('{{instalacion.firma_cliente}}', $findings['FIRMA_CLIENTE_NO_BORRAR']['message']);
    }

    /**
     * dompdf corre con enable_remote = false: una imagen enlazada a internet
     * sale rota siempre, aunque el editor la muestre perfecta. Es la causa
     * más desconcertante de las tres del reporte original.
     */
    public function test_it_detects_images_linked_from_the_internet(): void
    {
        $html = '<img src="https://wisphub.app/media/uploads/logo.jpg" style="width:250px" />';

        $findings = $this->diagnostics->inspect($html, 'contract');

        $this->assertCount(1, $findings);
        $this->assertSame(TemplateDiagnostics::KIND_REMOTE_IMAGE, $findings[0]['kind']);
        $this->assertStringContainsString('{{empresa.logo}}', $findings[0]['message']);
    }

    public function test_a_local_or_embedded_image_is_not_reported(): void
    {
        $html = '<img src="/storage/logos/mi-logo.png" /><img src="data:image/png;base64,AAAA" />';

        $this->assertSame([], $this->diagnostics->inspect($html, 'contract'));
    }

    public function test_each_finding_is_reported_once_no_matter_how_many_times_it_appears(): void
    {
        $html = '<p>{{fecha_instalacion}}</p><p>{{ fecha_instalacion }}</p><p>{{fecha_instalacion}}</p>';

        $this->assertCount(1, $this->diagnostics->inspect($html, 'contract'));
    }

    /**
     * Los avisos viajan en una cabecera HTTP. Sin tope, una plantilla migrada
     * entera pasa del límite del proxy y el navegador se queda sin el PDF —
     * peor que un aviso incompleto.
     */
    public function test_it_caps_the_number_of_findings(): void
    {
        $html = collect(range(1, 40))
            ->map(fn (int $i) => "<p>{{inventado_{$i}.campo}}</p>")
            ->implode('');

        $this->assertCount(TemplateDiagnostics::MAX_FINDINGS, $this->diagnostics->inspect($html, 'contract'));
    }

    /**
     * Cuando sobran hallazgos, los que se pierden por el tope tienen que ser
     * los cosméticos, no los que dejan el documento sin datos.
     */
    public function test_findings_that_blank_out_data_are_ranked_above_cosmetic_ones(): void
    {
        $html = '<img src="https://ejemplo.com/logo.png" />'
            . '<p>CO-NUMERO_CONTRATO_TAG</p>'
            . '<p>{{ plan_internet.precio }}</p>';

        $kinds = array_column($this->diagnostics->inspect($html, 'contract'), 'kind');

        $this->assertSame([
            TemplateDiagnostics::KIND_FOREIGN_MARKER,
            TemplateDiagnostics::KIND_FOREIGN_PLACEHOLDER,
            TemplateDiagnostics::KIND_REMOTE_IMAGE,
        ], $kinds);
    }

    /**
     * PlaceholderResolver no reconoce estos como marcador, así que **no los
     * blanquea: los imprime tal cual** en el PDF. Es un síntoma distinto —"me
     * sale un texto raro"— y por eso el escaneo normal no los veía. Los dos
     * casos vienen de un contrato real (2026-08-06).
     */
    public function test_it_detects_placeholders_with_unbalanced_braces_or_junk_inside(): void
    {
        $findings = $this->inspectByToken(
            '<p>{{plan.valor_mensual} y {{ cliente.cedula&nbsp;}}</p>',
            'contract'
        );

        $this->assertArrayHasKey('{{plan.valor_mensual}', $findings);
        $this->assertSame(
            TemplateDiagnostics::KIND_MALFORMED_PLACEHOLDER,
            $findings['{{plan.valor_mensual}']['kind']
        );
        $this->assertArrayHasKey('{{ cliente.cedula&nbsp;}}', $findings);
    }

    public function test_a_well_formed_placeholder_is_never_reported_as_malformed(): void
    {
        $kinds = array_column(
            $this->diagnostics->inspect('<p>{{cliente.nombre}} {{ contrato.fecha }}</p>', 'contract'),
            'kind'
        );

        $this->assertNotContains(TemplateDiagnostics::KIND_MALFORMED_PLACEHOLDER, $kinds);
    }

    /**
     * El caso más caro: el editor visual muestra el documento perfecto porque
     * es un navegador, pero el modo seguro lo desarma al renderizar y lo mete
     * dentro del shell fijo. El PDF no se parece en nada al editor.
     */
    public function test_it_warns_when_a_full_document_is_going_to_render_in_safe_mode(): void
    {
        $html = '<!DOCTYPE html><html><body><table width="475"><tr><td>Hola</td></tr></table></body></html>';

        $kinds = array_column($this->diagnostics->inspect($html, 'contract', false), 'kind');

        $this->assertSame(TemplateDiagnostics::KIND_NEEDS_ADVANCED_MODE, $kinds[0], 'Debe ir primero: es lo más grave.');
    }

    public function test_the_same_document_in_advanced_mode_does_not_trigger_that_warning(): void
    {
        $html = '<!DOCTYPE html><html><body><table width="475"><tr><td>Hola</td></tr></table></body></html>';

        $kinds = array_column($this->diagnostics->inspect($html, 'contract', true), 'kind');

        $this->assertNotContains(TemplateDiagnostics::KIND_NEEDS_ADVANCED_MODE, $kinds);
    }

    /**
     * Texto con formato básico es exactamente para lo que existe el modo
     * seguro: avisar ahí sería ruido en el caso normal.
     */
    public function test_plain_formatted_text_in_safe_mode_is_not_warned_about(): void
    {
        $kinds = array_column(
            $this->diagnostics->inspect('<p><strong>Hola</strong> {{cliente.nombre}}</p>', 'contract', false),
            'kind'
        );

        $this->assertNotContains(TemplateDiagnostics::KIND_NEEDS_ADVANCED_MODE, $kinds);
    }

    public function test_orphaned_blocks_are_merged_into_the_same_channel(): void
    {
        $findings = $this->diagnostics->inspectWithOrphanedBlocks(
            '<p>{{ plan_internet.precio }}</p>',
            'contract',
            ['empresa.logo', 'empresa.logo']
        );

        $kinds = array_column($findings, 'kind');

        $this->assertSame([
            TemplateDiagnostics::KIND_FOREIGN_PLACEHOLDER,
            TemplateDiagnostics::KIND_ORPHANED_BLOCK,
        ], $kinds, 'El bloque huérfano se reporta una sola vez y después de lo que blanquea datos.');
        $this->assertSame(
            config('document_placeholder_blocks.contract')['empresa.logo'],
            $findings[1]['label']
        );
    }

    /**
     * El diagnóstico no puede marcar como desconocido nada que
     * PlaceholderResolver sí resuelva: si divergen, el aviso miente. Este
     * test recorre el catálogo completo de los 3 tipos.
     */
    public function test_no_token_from_the_official_catalogue_is_ever_flagged(): void
    {
        foreach (['invoice', 'contract', 'installation'] as $type) {
            $tokens = array_merge(
                array_keys(config("document_placeholders.{$type}")),
                array_keys(config("document_placeholder_blocks.{$type}"))
            );

            $html = collect($tokens)->map(fn (string $t) => "<p>{{{$t}}}</p>")->implode('');

            $this->assertSame([], $this->diagnostics->inspect($html, $type), "Falso positivo en {$type}");
        }
    }

    // ── Fuentes (2026-08-06) ────────────────────────────────────────────
    //
    // dompdf no lee las fuentes del sistema: sólo conoce las 14 base del PDF y
    // las DejaVu que trae. El navegador del editor SÍ tiene Calibri, así que
    // ésta es de las diferencias editor↔PDF que no se pueden deducir mirando
    // — el texto simplemente ocupa distinto y los saltos de página se mueven.

    public function test_flags_a_font_family_that_dompdf_does_not_have(): void
    {
        $findings = $this->diagnostics->inspect(
            '<style>body { font-family: Calibri; }</style><p>x</p>',
            'contract'
        );

        $this->assertCount(1, $findings);
        $this->assertSame(TemplateDiagnostics::KIND_UNSUPPORTED_FONT, $findings[0]['kind']);
        $this->assertStringContainsString('Calibri', $findings[0]['token']);
    }

    /**
     * Una pila que TERMINA en una familia conocida sí funciona: dompdf recorre
     * la lista y se queda con la primera que reconoce. Avisar de ella sería
     * ruido, y el ruido es lo que hace que se ignore el panel entero.
     */
    public function test_does_not_flag_a_font_stack_that_ends_in_a_known_family(): void
    {
        $html = '<style>body { font-family: Calibri, Arial, sans-serif; }</style>'
            . '<p style="font-family: \'Times New Roman\', Times, serif;">x</p>'
            . '<div style="font-family: DejaVu Sans;">y</div>';

        $this->assertSame([], $this->diagnostics->inspect($html, 'contract'));
    }

    /**
     * Las fuentes van al final del orden de severidad: no dejan nada en
     * blanco, sólo cambian la letra. Con el tope de hallazgos lleno, lo que
     * se pierde tiene que ser esto y no un marcador que sale vacío.
     */
    public function test_font_findings_rank_below_broken_placeholders(): void
    {
        $findings = $this->diagnostics->inspect(
            '<style>body { font-family: Calibri; }</style><p>{{cliente.inventado}}</p>',
            'contract'
        );

        $this->assertSame(TemplateDiagnostics::KIND_UNKNOWN_PLACEHOLDER, $findings[0]['kind']);
        $this->assertSame(TemplateDiagnostics::KIND_UNSUPPORTED_FONT, $findings[1]['kind']);
    }
}
