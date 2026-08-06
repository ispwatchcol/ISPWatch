<?php

namespace App\Services\Templates;

use App\Models\DocumentTemplate;

/**
 * Única fuente de verdad de la geometría de página y de los defaults de
 * dompdf. Nace del reporte del 2026-08-06: el editor visual dibujaba los
 * cortes de página con números adivinados a mano y el PDF salía con el texto
 * en otro sitio, así que las líneas rojas mentían.
 *
 * Antes, HtmlDocumentEditor.vue tenía sus propias constantes (210×297 mm,
 * "margen de dompdf = 48 px por lado") copiadas a ojo. Dos copias del mismo
 * número en dos lenguajes distintos es exactamente la razón por la que el
 * editor podía mentir sin que nada fallara. Ahora el frontend NO calcula: pide
 * estos números al backend (DocumentTemplateController::show()).
 *
 * Los valores no son elegidos, son MEDIDOS del dompdf que tenemos instalado
 * (dompdf/dompdf 3.1.5) y verificados por tests/Unit/Services/PdfPageGeometryTest:
 *
 *   - margen de página: `@page { margin: 1.2cm }` en lib/res/html.css.
 *     El editor asumía 1.27 cm (48 px), 5 px de más por lado.
 *   - dpi 96 (config/dompdf.php) → 1 px CSS = 0.75 pt. dompdf convierte con
 *     `$val * 72 / $dpi` en Css\Style::length_in_pt.
 *   - tamaños de papel en puntos, de CPDF_Adapter::PAPER_SIZES.
 *   - font-size `medium` = 12 pt (Css\Style::$default_font_size) = 16 px a
 *     96 dpi, que es también el default del navegador.
 *   - line-height `normal` = 1.2 (Css\Style::$default_line_height). El
 *     navegador usa las métricas de la fuente (~1.15 en Times New Roman):
 *     un 4 % de deriva vertical, que en una página son ~1 línea de más.
 *   - default_font `serif` (config/dompdf.php) → Times-Roman de las base-14.
 *     Times New Roman es métricamente compatible, así que el navegador parte
 *     las líneas en el mismo sitio si se le pide esa familia.
 *   - body SIN margen (el navegador le pone 8 px por su cuenta).
 */
class PdfPageGeometry
{
    /** config/dompdf.php → options.dpi. */
    public const DPI = 96;

    /** `@page { margin: 1.2cm }` — default de dompdf/lib/res/html.css. */
    public const MARGIN_CM = 1.2;

    /** Css\Style::$default_font_size, en puntos. */
    public const DEFAULT_FONT_SIZE_PT = 12;

    /** Css\Style::$default_line_height (el valor de `line-height: normal`). */
    public const DEFAULT_LINE_HEIGHT = 1.2;

    /**
     * Familia que el navegador debe usar para imitar el default de dompdf
     * (`default_font = serif` → Times-Roman). Times New Roman comparte las
     * métricas de Times-Roman: mismos anchos de glifo, mismos saltos de línea.
     */
    public const DEFAULT_FONT_STACK = '"Times New Roman", Times, serif';

    private const PT_PER_INCH = 72;
    private const CM_PER_INCH = 2.54;

    /**
     * Puntos PostScript, en vertical (ancho × alto). Copiados de
     * Dompdf\Adapter\CPDF::$PAPER_SIZES.
     */
    private const PAPER_PT = [
        'a4'     => [595.28, 841.89],
        'letter' => [612.0, 792.0],
        'legal'  => [612.0, 1008.0],
    ];

    /**
     * Medidas de la hoja en píxeles CSS a 96 dpi — las mismas unidades en las
     * que el tenant escribe su plantilla, así que `printable_width_px` se
     * compara directamente contra el ancho que pide su diseño.
     *
     * @return array{page_size:string,page_orientation:string,margin_px:int,paper_width_px:int,paper_height_px:int,printable_width_px:int,printable_height_px:int}
     */
    public function metrics(?string $pageSize, ?string $pageOrientation): array
    {
        $size = in_array($pageSize, DocumentTemplate::PAGE_SIZES, true)
            ? $pageSize
            : DocumentTemplate::DEFAULT_PAGE_SIZE;

        $orientation = in_array($pageOrientation, DocumentTemplate::PAGE_ORIENTATIONS, true)
            ? $pageOrientation
            : DocumentTemplate::DEFAULT_PAGE_ORIENTATION;

        [$shortSidePt, $longSidePt] = self::PAPER_PT[$size];
        $landscape = $orientation === 'landscape';

        $widthPt  = $landscape ? $longSidePt : $shortSidePt;
        $heightPt = $landscape ? $shortSidePt : $longSidePt;
        $marginPt = $this->marginPt();

        return [
            'page_size'           => $size,
            'page_orientation'    => $orientation,
            'margin_px'           => $this->toPx($marginPt),
            'paper_width_px'      => $this->toPx($widthPt),
            'paper_height_px'     => $this->toPx($heightPt),
            'printable_width_px'  => $this->toPx($widthPt - 2 * $marginPt),
            'printable_height_px' => $this->toPx($heightPt - 2 * $marginPt),
        ];
    }

    /**
     * Las 6 combinaciones de tamaño × orientación, indexadas por
     * "{tamaño}:{orientación}". El editor cambia de hoja sin ir al servidor,
     * pero sigue usando números calculados aquí.
     *
     * @return array<string,array<string,int|string>>
     */
    public function allMetrics(): array
    {
        $all = [];

        foreach (DocumentTemplate::PAGE_SIZES as $size) {
            foreach (DocumentTemplate::PAGE_ORIENTATIONS as $orientation) {
                $all["{$size}:{$orientation}"] = $this->metrics($size, $orientation);
            }
        }

        return $all;
    }

    /**
     * CSS que se inyecta en el documento PDF (modo avanzado), ANTES del
     * <style> del tenant para que él siga pudiendo sobreescribirlo.
     *
     * Deliberadamente NO cambia cómo se ve nada: declara explícitamente lo
     * que dompdf ya hacía por defecto. El valor está en fijarlo por contrato,
     * para que una actualización de dompdf que cambie el default no mueva en
     * silencio todos los documentos ya diseñados de todos los tenants — y
     * para que el editor pueda dibujar los cortes de página sabiendo el
     * margen real, no uno adivinado.
     *
     * `size` NO se declara en el @page a propósito: el tamaño lo fija
     * TemplateRenderer::applyPaper() con setPaper(), y declararlo dos veces
     * es una forma barata de que un día no coincidan.
     */
    public function documentBaseCss(): string
    {
        return '@page{margin:' . self::MARGIN_CM . 'cm}'
            . 'body{margin:0;padding:0}';
    }

    /**
     * CSS que se inyecta en el iframe del editor, ANTES del <style> del
     * tenant. No toca el PDF: su único trabajo es apagar las diferencias
     * entre los defaults del navegador y los de dompdf, que son la razón de
     * que el mismo HTML se parta en páginas distintas en cada uno.
     */
    public function editorBaseCss(): string
    {
        return implode("\n", [
            '/* Normalización editor↔dompdf — App\\Services\\Templates\\PdfPageGeometry */',
            'html{margin:0;padding:0}',
            // El navegador le pone 8px de margen al body; dompdf, ninguno.
            'body{margin:0;padding:0;background:#fff;color:#000;',
            '  font-family:' . self::DEFAULT_FONT_STACK . ';',
            '  font-size:' . self::DEFAULT_FONT_SIZE_PT . 'pt;',
            // `normal` vale 1.2 en dompdf y ~1.15 en el navegador: sin fijarlo,
            // una página entera termina con casi una línea de diferencia.
            '  line-height:' . self::DEFAULT_LINE_HEIGHT . '}',
        ]);
    }

    /**
     * Tipografía con la que el editor debe mostrar un fragmento en MODO
     * SEGURO. Ahí el contenido del tenant no es el documento: se inserta
     * dentro de `.custom-block` del shell fijo
     * (resources/views/documents/shells/{type}_shell.blade.php), que impone su
     * propia letra y su propio tamaño. El editor mostraba Times a 13 px
     * mientras la factura salía en DejaVu Sans a 9 px — el mismo párrafo
     * ocupaba casi el doble de alto en pantalla que en el PDF.
     *
     * Los valores están copiados de esos shells y atados a ellos por
     * PdfPageGeometryTest::test_fragment_typography_matches_the_shells(), que
     * los lee del Blade: si alguien cambia el shell y no esto, el test falla.
     */
    public function editorFragmentCss(string $type): string
    {
        $typography = self::SHELL_TYPOGRAPHY[$type] ?? self::SHELL_TYPOGRAPHY[DocumentTemplate::TYPE_CONTRACT];

        return implode("\n", [
            '/* Tipografía del shell fijo — App\\Services\\Templates\\PdfPageGeometry */',
            'body{margin:0;padding:0;background:#fff;',
            '  font-family:"DejaVu Sans", sans-serif;',
            '  font-size:' . $typography['font_size_px'] . 'px;',
            '  line-height:' . $typography['line_height'] . ';',
            '  color:' . $typography['color'] . ';',
            '  text-align:' . $typography['text_align'] . '}',
            'table{border-collapse:collapse}',
            'td,th{padding:4px 6px}',
            'img{max-width:100%}',
        ]);
    }

    /**
     * Espejo de `.custom-block` (y del `body` que lo contiene) en cada shell.
     * Ver editorFragmentCss().
     */
    private const SHELL_TYPOGRAPHY = [
        DocumentTemplate::TYPE_INVOICE => [
            'font_size_px' => 9,
            'line_height'  => 1.6,
            'color'        => '#333',
            'text_align'   => 'left',
        ],
        DocumentTemplate::TYPE_CONTRACT => [
            'font_size_px' => 12,
            'line_height'  => 1.6,
            'color'        => '#1f2937',
            'text_align'   => 'justify',
        ],
        DocumentTemplate::TYPE_INSTALLATION => [
            'font_size_px' => 11,
            'line_height'  => 1.5,
            'color'        => '#1f2937',
            'text_align'   => 'left',
        ],
    ];

    private function marginPt(): float
    {
        return self::MARGIN_CM / self::CM_PER_INCH * self::PT_PER_INCH;
    }

    private function toPx(float $pt): int
    {
        return (int) round($pt * self::DPI / self::PT_PER_INCH);
    }
}
