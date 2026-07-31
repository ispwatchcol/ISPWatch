<?php

namespace Tests\Unit\Services;

use App\Services\Templates\BlockMarkerInjector;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Covers the design approved 2026-07-31: opaque marker (one per token,
 * reused across occurrences), DOM-based splice restricted to text-node
 * position, orphaned markers blanked + logged instead of left visible or
 * silently dropped.
 */
class BlockMarkerInjectorTest extends TestCase
{
    private BlockMarkerInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->injector = new BlockMarkerInjector();
    }

    public function test_replaces_a_block_token_in_content_position(): void
    {
        $result = $this->injector->inject(
            '<div>{{factura.tabla_items}}</div>',
            ['factura.tabla_items' => '<table><tr><td>Ítem</td></tr></table>'],
            1,
            'invoice'
        );

        $this->assertSame('<div><table><tr><td>Ítem</td></tr></table></div>', $result);
    }

    public function test_leaves_html_untouched_when_the_template_does_not_use_the_block_token(): void
    {
        Log::shouldReceive('warning')->never();

        $result = $this->injector->inject(
            '<p>Sin bloques aquí.</p>',
            ['factura.tabla_items' => '<table></table>'],
            1,
            'invoice'
        );

        $this->assertSame('<p>Sin bloques aquí.</p>', $result);
    }

    /**
     * Caso adversarial acordado en el diseño: el mismo token aparece dos
     * veces, una dentro de un atributo y otra en contenido. Solo la de
     * contenido debe expandirse a HTML real; la del atributo nunca debe
     * convertirse en markup — se limpia a vacío y se loguea como huérfana.
     */
    public function test_only_expands_the_content_position_occurrence_and_blanks_the_attribute_one(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) =>
                str_contains($message, 'factura.tabla_items')
                && $context['token'] === 'factura.tabla_items'
                && $context['tenant_id'] === 7
                && $context['document_type'] === 'invoice'
            );

        $html = '<p>Resumen: <span title="ver {{factura.tabla_items}} completo">detalle</span></p>'
            . '<div>{{factura.tabla_items}}</div>';

        $result = $this->injector->inject(
            $html,
            ['factura.tabla_items' => '<table id="items"><tr><td>Plan Hogar</td></tr></table>'],
            7,
            'invoice'
        );

        // La ocurrencia de contenido sí se expandió.
        $this->assertStringContainsString('<table id="items"><tr><td>Plan Hogar</td></tr></table>', $result);
        // Solo debe haber UNA tabla real en todo el documento — no dos, y no
        // una fusionada a mitad del atributo.
        $this->assertSame(1, substr_count($result, '<table'));

        // El atributo `title` no contiene HTML real: ni la tabla completa...
        $this->assertDoesNotMatchRegularExpression('/title="[^"]*<table/', $result);
        // ...ni el marcador crudo (nunca queda visible, ver punto 1 aprobado).
        $this->assertDoesNotMatchRegularExpression('/title="[^"]*BLOCKMARK_/', $result);
        $this->assertStringNotContainsString('BLOCKMARK_', $result);
    }

    /**
     * Dos placeholders de bloque DISTINTOS pegados en el mismo nodo de texto
     * (sin ninguna tag entre ellos) — ambos deben expandirse correctamente
     * en una sola pasada, sin que el segundo se pierda por el nodo quedar
     * "consumido" tras resolver el primero.
     */
    public function test_expands_two_different_block_tokens_within_the_same_text_node(): void
    {
        $result = $this->injector->inject(
            '<div>{{instalacion.firma_cliente}} y {{instalacion.firma_tecnico}}</div>',
            [
                'instalacion.firma_cliente'  => '<img src="cliente.png" alt="Firma cliente">',
                'instalacion.firma_tecnico'  => '<img src="tecnico.png" alt="Firma técnico">',
            ],
            1,
            'installation'
        );

        $this->assertStringContainsString('<img src="cliente.png" alt="Firma cliente">', $result);
        $this->assertStringContainsString('<img src="tecnico.png" alt="Firma técnico">', $result);
        $this->assertStringContainsString(' y ', $result);
    }

    /**
     * El mismo token repetido dos veces en posiciones de contenido distintas
     * reutiliza UN solo marcador (no uno por ocurrencia) y ambas apariciones
     * se expanden — mismo criterio que ya rige para placeholders escalares.
     */
    public function test_reuses_one_marker_per_token_and_expands_every_content_occurrence(): void
    {
        $result = $this->injector->inject(
            '<div>{{instalacion.fotos}}</div><div>{{instalacion.fotos}}</div>',
            ['instalacion.fotos' => '<img src="foto.jpg">'],
            1,
            'installation'
        );

        $this->assertSame(2, substr_count($result, '<img src="foto.jpg">'));
    }
}
