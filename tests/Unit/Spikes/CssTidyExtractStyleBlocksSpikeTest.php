<?php

namespace Tests\Unit\Spikes;

use HTMLPurifier;
use HTMLPurifier_Config;
use Tests\TestCase;

/**
 * Spike aislado — NO forma parte de App\Services\Templates\TemplateSanitizer
 * ni de ningún flujo de producción. Responde una sola pregunta antes de
 * aprobar el "modo avanzado" de plantillas (auditoría 2026-07-30): contra la
 * versión de ezyang/htmlpurifier ya instalada en este proyecto más
 * cerdic/css-tidy (agregada solo como dependencia --dev para este spike),
 * ¿Filter.ExtractStyleBlocks neutraliza @import, expression() y
 * behavior:url() dentro de un bloque <style>? Y, por separado, ¿el scoping
 * (Filter.ExtractStyleBlocks.Scope) evita que un selector como ".hdr" del
 * tenant pise una clase del shell fijo?
 *
 * cleanCss() replica el uso mínimo del filtro: ExtractStyleBlocks no reinyecta
 * el CSS limpio en el HTML devuelto por purify() (ese es justamente el punto
 * del filtro — lo separa para que el caller decida dónde ponerlo), así que se
 * recupera desde $purifier->context->get('StyleBlocks') tal como lo haría un
 * consumidor real.
 */
class CssTidyExtractStyleBlocksSpikeTest extends TestCase
{
    private function cleanCss(string $css, ?string $scope = null): string
    {
        $config = HTMLPurifier_Config::createDefault();
        // Note: 'style' must NOT be listed here — ExtractStyleBlocks::preFilter()
        // strips <style> blocks out of the HTML via regex *before* HTMLPurifier's
        // core HTML validator ever sees them (that's how the filter is able to
        // hand raw CSS to csstidy instead of the HTML validator). Listing 'style'
        // as an allowed *element* just makes the (now style-less) validator warn
        // that a still-configured-but-absent element isn't supported.
        $config->set('HTML.Allowed', 'div');
        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'text-align', 'font-weight']);
        $config->set('Filter.ExtractStyleBlocks', true);
        if ($scope !== null) {
            $config->set('Filter.ExtractStyleBlocks.Scope', $scope);
        }
        $config->set('Cache.SerializerPath', storage_path('framework/cache/htmlpurifier'));

        $purifier = new HTMLPurifier($config);
        $purifier->purify('<div><style>' . $css . '</style></div>');

        return implode("\n", $purifier->context->get('StyleBlocks'));
    }

    public function test_strips_at_import_from_style_block(): void
    {
        $cleaned = $this->cleanCss('@import url("https://evil.test/exfil.css"); p { color: red; }');

        $this->assertStringNotContainsString('@import', $cleaned);
        $this->assertStringNotContainsString('evil.test', $cleaned);
    }

    public function test_strips_ie_expression_from_a_property_value(): void
    {
        $cleaned = $this->cleanCss('p { width: expression(alert(1)); color: blue; }');

        $this->assertStringNotContainsString('expression', $cleaned);
        $this->assertStringNotContainsString('alert', $cleaned);
    }

    public function test_strips_ie_behavior_url_property(): void
    {
        $cleaned = $this->cleanCss('p { behavior: url(evil.htc); color: green; }');

        $this->assertStringNotContainsString('behavior', $cleaned);
        $this->assertStringNotContainsString('evil.htc', $cleaned);
    }

    public function test_keeps_an_allowed_property_with_a_safe_value(): void
    {
        // Selector must be an element present in HTML.Allowed ('div' here) —
        // ExtractStyleBlocks validates selectors against the HTML definition
        // too, not just property names, so an arbitrary tag like 'p' gets
        // dropped even though 'color' itself would have been fine.
        $cleaned = $this->cleanCss('div { color: #ff0000; }');

        $this->assertStringContainsString('color', $cleaned);
        $this->assertStringContainsString('#FF0000', strtoupper($cleaned));
    }

    /**
     * No pedido explícitamente en la auditoría, pero es la evidencia que
     * sostiene la recomendación central del punto 1: sin
     * Filter.ExtractStyleBlocks.Scope, un tenant podría escribir un selector
     * que pise una clase del shell fijo (ej. ".tot-grand" en
     * invoice_shell.blade.php) y ocultar/falsificar visualmente un total.
     */
    public function test_scope_prefixes_every_selector_so_tenant_css_cannot_target_the_shell(): void
    {
        $cleaned = $this->cleanCss('.tot-grand { color: red; }', '.tenant-custom-block');

        // The whole point of scoping: the selector is not bare ".tot-grand"
        // anymore, it's prefixed — so it can never match the shell's element
        // outside of the tenant's own custom-block wrapper.
        $this->assertMatchesRegularExpression('/^\.tenant-custom-block \.tot-grand\b/', trim($cleaned));
    }
}
