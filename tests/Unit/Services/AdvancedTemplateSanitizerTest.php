<?php

namespace Tests\Unit\Services;

use App\Services\Templates\AdvancedTemplateSanitizer;
use Tests\TestCase;

/**
 * Cobertura de las reglas NO NEGOCIABLES del modo avanzado (auditoría
 * 2026-08-01): script, atributos on-*, url(), @import, expression() y
 * behavior SIEMPRE bloqueados, sin importar la presión de tiempo bajo la
 * que se implementó esta clase. Ver también el docblock de la clase.
 */
class AdvancedTemplateSanitizerTest extends TestCase
{
    private AdvancedTemplateSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new AdvancedTemplateSanitizer();
    }

    // ── Negativos: lo que NUNCA debe sobrevivir ─────────────────────────

    public function test_strips_script_tags_and_their_content(): void
    {
        $result = $this->sanitizer->sanitize('<div>Hola</div><script>alert(document.cookie)</script>');

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Hola', $result);
    }

    public function test_strips_inline_event_handlers(): void
    {
        $result = $this->sanitizer->sanitize('<div onclick="alert(1)" onmouseover="steal()">Hola</div>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringContainsString('Hola', $result);
    }

    public function test_strips_at_import_from_style_block(): void
    {
        $result = $this->sanitizer->sanitize('<style>@import url("https://evil.test/exfil.css");</style><div>x</div>');

        $this->assertStringNotContainsString('@import', $result);
        $this->assertStringNotContainsString('evil.test', $result);
    }

    public function test_strips_expression_from_a_css_value(): void
    {
        $result = $this->sanitizer->sanitize('<style>.x { width: expression(alert(1)); }</style><div class="x">y</div>');

        $this->assertStringNotContainsString('expression', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    public function test_strips_behavior_property(): void
    {
        $result = $this->sanitizer->sanitize('<style>.x { behavior: url(evil.htc); }</style><div class="x">y</div>');

        $this->assertStringNotContainsString('behavior', $result);
        $this->assertStringNotContainsString('evil.htc', $result);
    }

    public function test_strips_url_from_background_image_and_from_shorthand(): void
    {
        // background-image/background (shorthand) y list-style-image no
        // están en el allowlist a propósito — url() nunca puede colarse por
        // ninguna propiedad CSS, ni siquiera apuntando a un host permitido.
        $result = $this->sanitizer->sanitize(
            '<style>.x { background-image: url(https://example.test/x.png); background: url(https://example.test/y.png) red; }</style>'
            . '<ul><li class="x">y</li></ul>'
        );

        $this->assertStringNotContainsString('url(', $result);
        $this->assertStringNotContainsString('background-image', $result);
    }

    public function test_strips_javascript_scheme_from_href_and_img_src(): void
    {
        $result = $this->sanitizer->sanitize(
            '<a href="javascript:alert(1)">click</a><img src="javascript:alert(1)" alt="x">'
        );

        $this->assertStringNotContainsString('javascript:', $result);
    }

    /**
     * `data:` se habilitó el 2026-08-06 SÓLO para imágenes reales: es la única
     * forma de que una imagen pegada en la plantilla llegue al PDF, porque
     * dompdf corre con enable_remote=false y nunca descarga una http(s).
     * Lo que no cambia es que un payload que no sea una imagen de verdad se
     * cae entero — HTMLPurifier mira los BYTES, no el mime que declara la URI.
     */
    public function test_strips_a_data_uri_that_is_not_a_real_image(): void
    {
        $result = $this->sanitizer->sanitize(
            '<img src="data:image/png;base64,AAAA" alt="x">'
            . '<img src="data:text/html;base64,' . base64_encode('<script>alert(1)</script>') . '" alt="y">'
            . '<img src="data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>') . '" alt="z">'
        );

        $this->assertStringNotContainsString('data:image/png;base64,AAAA', $result, 'un payload trunco no es una imagen');
        $this->assertStringNotContainsString('data:text/html', $result);
        $this->assertStringNotContainsString('svg', $result, 'SVG no está en los tipos permitidos: puede llevar script');
        $this->assertStringNotContainsString('alert', $result);
    }

    public function test_keeps_a_data_uri_with_a_real_png(): void
    {
        $png = 'data:image/png;base64,' . base64_encode($this->onePixelPng());

        $result = $this->sanitizer->sanitize('<img src="' . $png . '" alt="logo">');

        $this->assertStringContainsString('data:image/png;base64,', $result);
        $this->assertStringContainsString('alt="logo"', $result);
    }

    /** PNG de 1×1 válido: los bytes tienen que pasar exif_imagetype/getimagesize. */
    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    // ── Selectores html/body (2026-08-06) ───────────────────────────────
    //
    // Antes se descartaban enteros porque HTMLPurifier no sabe modelar esos
    // elementos, y ahí es donde una plantilla exportada de Word pone su
    // tipografía base. El PDF salía con los defaults de dompdf mientras el
    // editor mostraba la letra del tenant. Ver el docblock de la clase.

    public function test_keeps_a_body_rule_with_its_declarations(): void
    {
        $style = $this->sanitizer->sanitizeParts(
            '<html><head><style>body { font-family: Arial, sans-serif; font-size: 11px; }</style></head>'
            . '<body><p>x</p></body></html>'
        )['style'];

        $this->assertStringContainsString('body {', $style);
        $this->assertStringContainsString('font-family:Arial, sans-serif', $style);
        $this->assertStringContainsString('font-size:11px', $style);
    }

    public function test_keeps_an_html_rule_and_a_body_rule_inside_a_media_block(): void
    {
        $style = $this->sanitizer->sanitizeParts(
            '<html><head><style>html { margin: 0; } @media print { body { font-size: 10px; } }</style></head>'
            . '<body><p>x</p></body></html>'
        )['style'];

        $this->assertStringContainsString('html {', $style);
        $this->assertStringContainsString('margin:0', $style);
        $this->assertStringContainsString('body {', $style);
        $this->assertStringContainsString('font-size:10px', $style);
    }

    public function test_keeps_body_when_it_shares_a_selector_list(): void
    {
        $style = $this->sanitizer->sanitizeParts(
            '<html><head><style>body, td { text-align: justify; }</style></head><body><p>x</p></body></html>'
        )['style'];

        $this->assertStringContainsString('body', $style);
        $this->assertStringContainsString('text-align:justify', $style);
    }

    /**
     * El enmascarado sólo puede tocar el nombre de elemento suelto. Una clase
     * `.body-note`, un id `#body` o un valor de declaración que contenga esa
     * palabra tienen que salir tal cual.
     */
    public function test_masking_never_touches_classes_ids_or_declaration_values(): void
    {
        $style = $this->sanitizer->sanitizeParts(
            '<html><head><style>.body-note { color: #333; } #body { color: #444; }'
            . ' .x { font-family: body, serif; }</style></head><body><p>x</p></body></html>'
        )['style'];

        $this->assertStringContainsString('.body-note', $style);
        $this->assertStringContainsString('#body', $style);
        $this->assertStringContainsStringIgnoringCase('font-family:body, serif', $style);
    }

    /** La máscara es interna: si se filtrara, el PDF traería una clase inventada. */
    public function test_the_selector_mask_never_leaks_into_the_output(): void
    {
        $result = $this->sanitizer->sanitize(
            '<html><head><style>body { color: #111; } html { margin: 0; }</style></head>'
            . '<body><p>x</p></body></html>'
        );

        $this->assertStringNotContainsString('ispwatch-doc', $result);
    }

    /**
     * Rescatar el selector NO rescata las declaraciones: pasan por el mismo
     * allowlist de CSS que cualquier otra regla. Si esto se rompiera, `body`
     * sería un agujero por el que entra todo lo que la clase promete bloquear.
     */
    public function test_a_body_rule_is_still_subject_to_the_css_allowlist(): void
    {
        $style = $this->sanitizer->sanitizeParts(
            '<html><head><style>body { position: absolute; z-index: 9999; behavior: url(evil.htc);'
            . ' background-image: url(https://evil.test/x.png); }</style></head><body><p>x</p></body></html>'
        )['style'];

        $this->assertStringNotContainsString('position', $style);
        $this->assertStringNotContainsString('z-index', $style);
        $this->assertStringNotContainsString('behavior', $style);
        $this->assertStringNotContainsString('url(', $style);
        $this->assertStringNotContainsString('evil.test', $style);
    }

    public function test_never_enables_position_or_z_index_even_though_broad_css_is_allowed(): void
    {
        $result = $this->sanitizer->sanitize(
            '<style>.x { position: absolute; top: 0; left: 0; z-index: 9999; }</style><div class="x">y</div>'
        );

        $this->assertStringNotContainsString('position', $result);
        $this->assertStringNotContainsString('z-index', $result);
    }

    public function test_strips_iframe_and_object_tags(): void
    {
        $result = $this->sanitizer->sanitize('<iframe src="https://evil.test"></iframe><object data="x"></object><p>Ok</p>');

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringNotContainsString('<object', $result);
        $this->assertStringContainsString('Ok', $result);
    }

    // ── Positivos: lo que SÍ debe sobrevivir (es "modo avanzado", no el shell fijo) ──

    public function test_keeps_a_full_document_skeleton_with_style_block(): void
    {
        $result = $this->sanitizer->sanitize(
            '<html><head><title>x</title><style>.card{color:#1e5fa8;border:1px solid #ccc;border-radius:8px;}</style></head>'
            . '<body><div class="card"><h1>Factura</h1><p>Detalle</p></div></body></html>'
        );

        $this->assertStringContainsString('<style>', $result);
        $this->assertStringContainsStringIgnoringCase('color:#1e5fa8', $result);
        $this->assertStringContainsString('border-radius:8px', $result);
        $this->assertStringContainsString('<h1>Factura</h1>', $result);
        $this->assertStringContainsString('<p>Detalle</p>', $result);
        // El wrapper del tenant se descarta y se reconstruye con el propio;
        // el <title> (no soportado) no debe sobrevivir en ningún lado.
        $this->assertStringNotContainsString('<title>', $result);
        $this->assertStringStartsWith('<!DOCTYPE html><html><head><meta charset="UTF-8">', $result);
    }

    public function test_keeps_a_table_with_colspan_and_broad_css(): void
    {
        $result = $this->sanitizer->sanitize(
            '<table><tr><td colspan="2" style="text-align:right;font-weight:bold;">Total</td></tr></table>'
        );

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('colspan="2"', $result);
        $this->assertStringContainsString('text-align:right', $result);
        $this->assertStringContainsString('font-weight:bold', $result);
    }

    public function test_keeps_an_image_with_an_allowed_scheme(): void
    {
        $result = $this->sanitizer->sanitize('<img src="https://example.test/logo.png" alt="Logo" width="120" height="40">');

        $this->assertStringContainsString('src="https://example.test/logo.png"', $result);
        $this->assertStringContainsString('alt="Logo"', $result);
        $this->assertStringContainsString('width="120"', $result);
    }

    public function test_keeps_a_link_with_an_allowed_scheme(): void
    {
        $result = $this->sanitizer->sanitize('<a href="https://example.test">Ver más</a>');

        $this->assertStringContainsString('href="https://example.test"', $result);
        $this->assertStringContainsString('Ver más', $result);
    }

    public function test_keeps_placeholder_tokens_intact_as_plain_text(): void
    {
        // El sanitizer no sabe qué es un placeholder — {{...}} es texto
        // plano para HTMLPurifier, debe sobrevivir intacto para que
        // PlaceholderResolver/BlockMarkerInjector lo procesen después.
        $result = $this->sanitizer->sanitize(
            '<body><p>Hola {{cliente.nombre}}</p><div>{{factura.tabla_items}}</div></body>'
        );

        $this->assertStringContainsString('{{cliente.nombre}}', $result);
        $this->assertStringContainsString('{{factura.tabla_items}}', $result);
    }

    public function test_empty_input_produces_a_minimal_valid_document(): void
    {
        $result = $this->sanitizer->sanitize('');

        $this->assertStringContainsString('<html>', $result);
        $this->assertStringContainsString('<body>', $result);
    }

    // ── id/style en todos los tags (auditoría 2026-08-03) ───────────────

    public function test_keeps_style_attribute_on_a_p_tag(): void
    {
        // Antes de esta auditoría solo div/span/td/th tenían style — otra
        // propiedad cualquiera en un <p> (frecuente en plantillas reales
        // exportadas de WispHub) se perdía en silencio. page-break-before no
        // sirve aquí como ejemplo: si este <p> es el primer elemento del
        // documento, stripLeadingPageBreak() la retira a propósito (ver
        // tests dedicados más abajo) — se usa text-align en su lugar.
        $result = $this->sanitizer->sanitize('<p style="text-align:right;">x</p>');

        $this->assertStringContainsString('text-align:right', $result);
    }

    // ── página en blanco inicial en dompdf (auditoría 2026-08-04) ───────

    /**
     * Verificado empíricamente contra dompdf directo antes de implementar:
     * la MISMA estructura con/sin page-break-before en el primer elemento
     * del documento da 4 páginas vs. 2 — dompdf, a diferencia de un
     * navegador, no ignora el salto quando no hay página anterior de la que
     * rompar. Plantillas reales de WispHub envuelven CADA "página" lógica en
     * un <div style="page-break-before:always">, incluida la primera.
     */
    public function test_strips_page_break_before_only_on_the_first_element_of_the_document(): void
    {
        $result = $this->sanitizer->sanitize(
            '<div style="page-break-before:always; color:red;">primero</div>'
            . '<div style="page-break-before:always;">segundo</div>'
        );

        // Al primero se le retira SOLO la propiedad problemática — el resto
        // del style (aquí, color) sobrevive intacto (CSSTidy normaliza el
        // nombre de color a hex, mismo comportamiento ya documentado en esta
        // clase).
        $this->assertStringContainsStringIgnoringCase('<div style="color:#ff0000;">primero</div>', $result);
        // El segundo salto de página sí es real (separa contenido del
        // tenant) y se respeta tal cual.
        $this->assertStringContainsString('<div style="page-break-before:always;">segundo</div>', $result);
    }

    public function test_removes_the_style_attribute_entirely_when_page_break_before_was_the_only_property(): void
    {
        $result = $this->sanitizer->sanitize('<div style="page-break-before:always;">x</div>');

        $this->assertStringContainsString('<div>x</div>', $result);
        $this->assertStringNotContainsString('style=', $result);
    }

    // ── width/height como atributo HTML en table/td/th (auditoría 2026-08-04) ──

    /**
     * Plantillas reales de WispHub arman su layout de 2 columnas con
     * width="50%" como ATRIBUTO HTML, no como CSS — sin esto, ambas <td>
     * quedaban sin ancho explícito y dompdf rompía la maquetación de
     * columnas por completo (verificado end-to-end contra un HTML real).
     */
    public function test_keeps_width_attribute_on_table_and_td_for_column_layout(): void
    {
        $result = $this->sanitizer->sanitize(
            '<table width="100%"><tr><td width="50%">izquierda</td><td width="50%">derecha</td></tr></table>'
        );

        $this->assertStringContainsString('<table width="100%">', $result);
        $this->assertStringContainsString('<td width="50%">izquierda</td>', $result);
        $this->assertStringContainsString('<td width="50%">derecha</td>', $result);
    }

    public function test_keeps_width_attribute_on_td_and_th(): void
    {
        $result = $this->sanitizer->sanitize(
            '<table><tr><td width="475">a</td><th width="5%">b</th></tr></table>'
        );

        $this->assertStringContainsString('<td width="475">a</td>', $result);
        $this->assertStringContainsString('<th width="5%">b</th>', $result);
    }

    /**
     * `width`/`height` en <tr> no son válidos según HTMLPurifier (a
     * diferencia de table/td/th) — se descartan en silencio, nunca deben
     * quedar como atributo crudo ni romper el sanitizer.
     */
    public function test_tr_never_keeps_width_or_height_as_a_raw_attribute(): void
    {
        $result = $this->sanitizer->sanitize('<table><tr width="100%" height="20"><td>a</td></tr></table>');

        $this->assertStringNotContainsString('width="100%"', $result);
        $this->assertStringContainsString('<tr><td>a</td></tr>', $result);
    }

    // ── alturas fijas en tablas (auditoría 2026-08-04) ──────────────────

    /**
     * Medido sobre un contrato real exportado de WispHub: quitando SÓLO las
     * alturas fijas, el PDF pasa de 8 páginas con 3 en blanco a 7 con 1.
     * Ninguna otra variante (quitar los saltos de página, la tabla más ancha
     * que la hoja, o los <div> anidados) cambiaba nada.
     */
    public function test_strips_fixed_height_from_the_whole_table_family(): void
    {
        $result = $this->sanitizer->sanitize(
            '<table height="450" style="border:1px solid #000;">'
            . '<tr><td height="500">a</td><th style="height:20px;color:#333;">b</th></tr></table>'
        );

        $this->assertStringNotContainsString('height="450"', $result);
        $this->assertStringNotContainsString('height="500"', $result);
        $this->assertStringNotContainsString('height:20px', $result);
        // Sólo se retira la altura — el resto del estilo sobrevive intacto
        // (CSSTidy sólo normaliza los bloques <style>, no los atributos
        // style inline, así que los colores quedan tal cual se escribieron).
        $this->assertStringContainsString('border:1px solid #000;', $result);
        $this->assertStringContainsString('color:#333;', $result);
    }

    /**
     * El filtro es por nombre exacto de propiedad: min-height, max-height y
     * line-height no causan el problema de paginación y deben sobrevivir.
     */
    public function test_does_not_touch_min_max_or_line_height(): void
    {
        $result = $this->sanitizer->sanitize(
            '<table style="min-height:50px;"><tr><td style="line-height:96%;max-height:20px;">a</td></tr></table>'
        );

        $this->assertStringContainsString('min-height:50px', $result);
        $this->assertStringContainsString('line-height:96%', $result);
        $this->assertStringContainsString('max-height:20px', $result);
    }

    /** Fuera de la familia <table> la altura es legítima y no se toca. */
    public function test_keeps_height_on_images_and_divs(): void
    {
        $img = $this->sanitizer->sanitize('<img src="https://example.test/a.png" alt="x" width="100" height="50">');
        $this->assertStringContainsString('height="50"', $img);

        $div = $this->sanitizer->sanitize('<div style="height:40px;">a</div>');
        $this->assertStringContainsString('height:40px', $div);
    }

    public function test_keeps_id_attribute_on_a_div_and_it_survives_intact(): void
    {
        $result = $this->sanitizer->sanitize('<style>#clausulas{color:#333;}</style><div id="clausulas">x</div>');

        $this->assertStringContainsString('id="clausulas"', $result);
        // CSSTidy reformatea el selector (espacios/saltos de línea) al pasar
        // por Filter.ExtractStyleBlocks — mismo comportamiento ya documentado
        // para otros tests de esta clase, se verifica selector y propiedad
        // por separado en vez de la cadena exacta.
        $this->assertStringContainsString('#clausulas', $result);
        $this->assertStringContainsStringIgnoringCase('color:#333', $result);
    }

    public function test_strips_a_duplicate_id_but_keeps_the_first_occurrence(): void
    {
        // Attr.EnableID fuerza unicidad de id en todo el documento — no es
        // un bypass, HTMLPurifier valida esto de verdad.
        $result = $this->sanitizer->sanitize('<div id="clausulas">a</div><div id="clausulas">b</div>');

        $this->assertSame(1, substr_count($result, 'id="clausulas"'));
        $this->assertStringContainsString('a</div>', $result);
        $this->assertStringContainsString('<div>b</div>', $result);
    }

    public function test_strips_a_syntactically_invalid_id_value(): void
    {
        $result = $this->sanitizer->sanitize('<div id="javascript:alert(1)">x</div>');

        $this->assertStringNotContainsString('javascript:alert', $result);
        $this->assertStringContainsString('<div>x</div>', $result);
    }

    public function test_keeps_id_and_style_on_headings_lists_and_links(): void
    {
        $result = $this->sanitizer->sanitize(
            '<h1 id="titulo" style="color:#111;">Título</h1>'
            . '<ul id="lista" style="margin:0;"><li id="item1" style="color:red;">x</li></ul>'
            . '<a id="link1" href="https://example.test" style="color:blue;">Ver</a>'
        );

        $this->assertStringContainsString('id="titulo"', $result);
        $this->assertStringContainsString('id="lista"', $result);
        $this->assertStringContainsString('id="item1"', $result);
        $this->assertStringContainsString('id="link1"', $result);
        $this->assertStringContainsString('color:#111', $result);
        // CSSTidy normaliza nombres de color a hex (red -> #FF0000, blue ->
        // #0000FF) — mismo tipo de reformateo ya documentado en esta clase.
        $this->assertStringContainsStringIgnoringCase('color:#ff0000', $result);
        $this->assertStringContainsStringIgnoringCase('color:#0000ff', $result);
    }
}
