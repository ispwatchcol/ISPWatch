<?php

namespace Tests\Unit\Services;

use App\Services\Templates\PdfPageGeometry;
use Dompdf\Adapter\CPDF;
use Dompdf\Css\Style;
use Tests\TestCase;

/**
 * Estos tests no comprueban que la clase "haga bien la cuenta": comprueban que
 * sus números siguen siendo LOS DE DOMPDF. Se leen del paquete instalado (su
 * hoja de estilos, su tabla de papeles, sus constantes de Css\Style) en vez de
 * escribirse a mano aquí, porque el bug original del 2026-08-06 fue
 * exactamente eso — el editor tenía una copia a ojo de esos valores, la copia
 * se separó del original, y nada falló: sólo el PDF salía distinto a lo que
 * el tenant veía en pantalla.
 *
 * Si una actualización de dompdf cambia cualquiera de esos defaults, aquí se
 * rompe algo y hay que decidir a conciencia, en vez de descubrirlo por un
 * reporte de "el contrato se ve raro".
 */
class PdfPageGeometryTest extends TestCase
{
    private PdfPageGeometry $geometry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geometry = new PdfPageGeometry();
    }

    /** El margen declarado tiene que ser el que dompdf aplica de verdad. */
    public function test_margin_matches_the_dompdf_default_stylesheet(): void
    {
        $css = file_get_contents(base_path('vendor/dompdf/dompdf/lib/res/html.css'));

        $this->assertMatchesRegularExpression('/@page\s*\{\s*margin:\s*[\d.]+cm/', $css);
        preg_match('/@page\s*\{\s*margin:\s*([\d.]+)cm/', $css, $m);

        $this->assertSame(
            (float) $m[1],
            (float) PdfPageGeometry::MARGIN_CM,
            'PdfPageGeometry::MARGIN_CM ya no coincide con el @page de dompdf: el editor volvería a '
            . 'dibujar los cortes de página fuera de sitio.'
        );
    }

    public function test_paper_sizes_match_the_dompdf_table(): void
    {
        foreach (['a4' => 'a4', 'letter' => 'letter', 'legal' => 'legal'] as $ours => $theirs) {
            [, , $widthPt, $heightPt] = CPDF::$PAPER_SIZES[$theirs];

            $metrics = $this->geometry->metrics($ours, 'portrait');

            $this->assertSame((int) round($widthPt * 96 / 72), $metrics['paper_width_px'], "ancho de {$ours}");
            $this->assertSame((int) round($heightPt * 96 / 72), $metrics['paper_height_px'], "alto de {$ours}");
        }
    }

    public function test_editor_defaults_match_dompdf_css_defaults(): void
    {
        $this->assertSame(
            (float) Style::$default_line_height,
            (float) PdfPageGeometry::DEFAULT_LINE_HEIGHT,
            '`line-height: normal` en dompdf cambió de valor; el editor lo tiene fijado al viejo.'
        );

        $this->assertSame(
            (float) Style::$default_font_size,
            (float) PdfPageGeometry::DEFAULT_FONT_SIZE_PT,
            '`font-size: medium` en dompdf cambió de valor; el editor lo tiene fijado al viejo.'
        );
    }

    /**
     * Los números concretos del caso que originó todo esto. El editor decía
     * 698 × 1027 px y la hoja real son 703 × 1032: 5 px de más por lado,
     * suficiente para que una tabla que "cabía" en el editor se desbordara.
     */
    public function test_a4_printable_area(): void
    {
        $portrait = $this->geometry->metrics('a4', 'portrait');

        $this->assertSame(794, $portrait['paper_width_px']);
        $this->assertSame(1123, $portrait['paper_height_px']);
        $this->assertSame(703, $portrait['printable_width_px']);
        $this->assertSame(1032, $portrait['printable_height_px']);
        $this->assertSame(45, $portrait['margin_px']);
    }

    public function test_landscape_swaps_the_sides(): void
    {
        $portrait = $this->geometry->metrics('a4', 'portrait');
        $landscape = $this->geometry->metrics('a4', 'landscape');

        $this->assertSame($portrait['printable_height_px'], $landscape['printable_width_px']);
        $this->assertSame($portrait['printable_width_px'], $landscape['printable_height_px']);
    }

    /**
     * Misma red de seguridad que TemplateRenderer::applyPaper(): una fila con
     * basura en la columna no puede producir una hoja de tamaño imposible, y
     * mucho menos un error — el editor se dibuja con esto en cada tecla.
     */
    public function test_unknown_paper_falls_back_to_the_default(): void
    {
        $garbage = $this->geometry->metrics('papiro', 'diagonal');
        $default = $this->geometry->metrics('a4', 'portrait');

        $this->assertSame($default, $garbage);
        $this->assertSame($default, $this->geometry->metrics(null, null));
    }

    public function test_all_metrics_covers_every_paper_and_orientation(): void
    {
        $all = $this->geometry->allMetrics();

        $this->assertCount(6, $all);
        foreach (['a4', 'letter', 'legal'] as $size) {
            foreach (['portrait', 'landscape'] as $orientation) {
                $this->assertArrayHasKey("{$size}:{$orientation}", $all);
            }
        }
    }

    /**
     * El CSS del documento declara el margen y NADA de tamaño: el tamaño lo
     * fija setPaper() en TemplateRenderer::applyPaper(), y declararlo también
     * aquí es la forma barata de que un día no coincidan.
     */
    public function test_document_css_declares_the_margin_but_never_the_page_size(): void
    {
        $css = $this->geometry->documentBaseCss();

        $this->assertStringContainsString('@page{margin:' . PdfPageGeometry::MARGIN_CM . 'cm}', $css);
        $this->assertStringNotContainsString('size:', $css);
    }

    /**
     * La tipografía que el editor usa para un fragmento tiene que ser la del
     * shell que de verdad lo va a envolver. Se lee del Blade, no se copia:
     * si alguien cambia el shell y no esto, el editor vuelve a mentir sobre
     * cuánto ocupa el texto.
     */
    public function test_fragment_typography_matches_the_shells(): void
    {
        $shells = [
            'invoice'      => 'invoice_shell',
            'contract'     => 'contract_shell',
            'installation' => 'installation_shell',
        ];

        foreach ($shells as $type => $shell) {
            $blade = file_get_contents(base_path("resources/views/documents/shells/{$shell}.blade.php"));

            $this->assertMatchesRegularExpression(
                '/\.custom-block\s*\{[^}]*font-size:\s*([\d.]+)px/',
                $blade,
                "El shell de {$type} ya no declara font-size en .custom-block."
            );
            preg_match('/\.custom-block\s*\{[^}]*font-size:\s*([\d.]+)px/', $blade, $m);

            $this->assertStringContainsString(
                'font-size:' . $m[1] . 'px;',
                $this->geometry->editorFragmentCss($type),
                "La tipografía del editor para {$type} se separó de la de su shell."
            );
        }
    }
}
