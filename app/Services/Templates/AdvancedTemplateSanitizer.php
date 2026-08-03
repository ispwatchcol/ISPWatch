<?php

namespace App\Services\Templates;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizer para plantillas en "modo avanzado" (is_advanced_mode = true):
 * el tenant edita un documento HTML completo (<html><head><style>...
 * </head><body>...</body></html>>), no solo un fragmento acotado dentro de
 * un shell fijo como TemplateSanitizer. Deliberadamente una clase separada,
 * no una extensión de TemplateSanitizer — el allowlist es materialmente más
 * grande y necesita su propio escrutinio, con su propia batería de tests.
 *
 * Reglas NO NEGOCIABLES (auditoría 2026-08-01), verificadas con
 * tests/Unit/Services/AdvancedTemplateSanitizerTest.php:
 *   - <script> y atributos on* SIEMPRE bloqueados (default de HTMLPurifier;
 *     jamás se activa HTML.Trusted, que los habilitaría).
 *   - url()/@import en CSS SIEMPRE bloqueados: ninguna propiedad de la
 *     whitelist acepta un valor url() (se excluyen background-image,
 *     background/list-style shorthand, list-style-image a propósito), y
 *     Filter.ExtractStyleBlocks limpia @import explícitamente
 *     ($tidy->import = [] en la librería).
 *   - expression()/behavior SIEMPRE bloqueados: 'behavior' no está en el
 *     allowlist (HTMLPurifier descarta cualquier propiedad no listada), y
 *     expression() no es un valor válido para ninguna propiedad permitida.
 *   - URI.AllowedSchemes = http/https únicamente (para <img src>, <a href> Y
 *     cualquier url() que se colara) — mismo criterio que TemplateSanitizer.
 *   - dompdf sigue con enable_remote=false (config/dompdf.php) — esta clase
 *     no cambia ni depende de esa config para su seguridad, es una capa
 *     adicional, no un reemplazo.
 *   - CSS.Trusted NUNCA se activa (habilitaría position/top/left/right/
 *     bottom/z-index — overlays que podrían ocultar/falsificar contenido).
 *
 * <html>/<head>/<body> del tenant NO se validan como tags permitidos (HTMLPurifier
 * no está diseñado para eso) — se descartan y el documento final se reconstruye
 * con un esqueleto propio, inyectando ahí el bloque <style> ya limpiado por
 * Filter.ExtractStyleBlocks y el body ya purificado. Ver sanitize().
 */
class AdvancedTemplateSanitizer
{
    /**
     * Propiedades CSS permitidas. Deliberadamente NO incluye ninguna que
     * solo tenga sentido con url() (background-image, background/list-style
     * shorthand, list-style-image, cursor, content, border-image) — así
     * url() queda excluido sin depender solo del filtro de esquema de URI.
     */
    private const CSS_ALLOWED_PROPERTIES = [
        'text-align', 'direction', 'clear', 'float',
        'font', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight',
        'letter-spacing', 'word-spacing',
        'text-decoration', 'text-decoration-color', 'text-decoration-line', 'text-decoration-style',
        'text-indent', 'text-transform', 'line-height',
        'height', 'width', 'min-height', 'min-width', 'max-height', 'max-width',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width',
        'border-top-color', 'border-top-style', 'border-top-width',
        'border-right-color', 'border-right-style', 'border-right-width',
        'border-bottom-color', 'border-bottom-style', 'border-bottom-width',
        'border-left-color', 'border-left-style', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius',
        'border-bottom-left-radius', 'border-bottom-right-radius',
        'border-collapse', 'border-spacing', 'caption-side', 'table-layout', 'vertical-align',
        'white-space', 'list-style-type', 'list-style-position',
        'color', 'background-color',
        'page-break-after', 'page-break-before', 'page-break-inside',
        // Requieren CSS.AllowTricky (ver abajo) — no exponen url()/posicionamiento.
        'display', 'visibility', 'overflow', 'opacity',
    ];

    /**
     * Tags/atributos permitidos en el BODY del documento del tenant. <html>,
     * <head>, <style>, <body> se manejan aparte (ver sanitize()) — nunca
     * pasan por este allowlist de contenido.
     */
    private const HTML_ALLOWED = [
        // 'class' en (casi) todos los tags: sin esto, un selector CSS por
        // clase en <style> (ej. ".card { ... }") no tiene forma de
        // aplicarse — el atributo class es lo que lo conecta al elemento.
        'div[class]', 'p[class]', 'br',
        'h1[class]', 'h2[class]', 'h3[class]', 'h4[class]', 'h5[class]', 'h6[class]',
        'strong', 'b', 'em', 'i', 'u',
        'ul[class]', 'ol[class]', 'li[class]',
        'span[style|class]',
        'a[href|class]',
        'table[class]', 'thead', 'tbody', 'tr[class]',
        'td[colspan|rowspan|style|class]', 'th[colspan|rowspan|style|class]',
        'img[src|alt|width|height|class]',
    ];

    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.Allowed', implode(',', self::HTML_ALLOWED));
        $config->set('CSS.AllowedProperties', self::CSS_ALLOWED_PROPERTIES);
        // display/visibility/overflow/opacity viven detrás de este flag en
        // esta versión de HTMLPurifier — no habilita nada relacionado a
        // url()/posicionamiento, sólo estas 4 propiedades adicionales.
        $config->set('CSS.AllowTricky', true);
        // border-radius (y sus 4 variantes por esquina) viven detrás de este
        // flag, no de AllowTricky — también habilita colores de scrollbar
        // IE-only y -moz-opacity/-khtml-opacity, inertes para dompdf, no
        // representan riesgo de seguridad.
        $config->set('CSS.Proprietary', true);
        // NUNCA true: habilitaría position/top/left/right/bottom/z-index,
        // que permiten overlays para ocultar/falsificar contenido — riesgo
        // real en un documento fiscal/legal, no solo teórico.
        $config->set('CSS.Trusted', false);
        // NUNCA true: habilitaría <script> y atributos on* directamente.
        $config->set('HTML.Trusted', false);

        $config->set('Filter.ExtractStyleBlocks', true);
        // Sin Scope: en modo avanzado el tenant es dueño del documento
        // completo, no hay shell fijo que proteger de selectores CSS ajenos.

        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('Cache.SerializerPath', $this->cacheDir());

        $this->purifier = new HTMLPurifier($config);
    }

    /**
     * Sanea un documento HTML completo enviado por el tenant y devuelve un
     * documento HTML completo, ya seguro, listo para Pdf::loadHTML(). El
     * esqueleto <html><head><meta><style></head><body>...</body></html> lo
     * construye este método, no HTMLPurifier — <html>/<head>/<body> del
     * tenant se descartan (HTMLPurifier no valida tags de documento, solo
     * contenido de body) y el <style> se re-inyecta ya limpio.
     */
    public function sanitize(?string $html): string
    {
        ['body' => $body, 'style' => $style] = $this->sanitizeParts($html);

        return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . ($style !== '' ? '<style>' . $style . '</style>' : '')
            . '</head><body>' . $body . '</body></html>';
    }

    /**
     * Igual que sanitize(), pero devuelve el body y el <style> ya limpios
     * por separado en vez de un documento ya ensamblado — usado por
     * TemplateRenderer::compileAdvanced() para sustituir placeholders solo
     * dentro del body antes de reensamblar el documento final (el <style>
     * nunca tiene placeholders, no tiene sentido tocarlo dos veces).
     *
     * @return array{body:string,style:string}
     */
    public function sanitizeParts(?string $html): array
    {
        $raw = (string) $html;

        $body = $this->purifier->purify($raw);
        $styleBlocks = $this->purifier->context->get('StyleBlocks') ?? [];

        return ['body' => $body, 'style' => implode("\n", $styleBlocks)];
    }

    private function cacheDir(): string
    {
        $dir = storage_path('framework/cache/htmlpurifier');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
