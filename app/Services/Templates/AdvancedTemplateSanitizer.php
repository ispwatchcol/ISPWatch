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
 *   - URI.AllowedSchemes = http/https/data (para <img src>, <a href> Y
 *     cualquier url() que se colara). `data` se agregó el 2026-08-06 y es el
 *     ÚNICO esquema que produce una imagen que de verdad sale en el PDF:
 *     http/https no se descargan nunca (enable_remote=false), así que una
 *     imagen pegada quedaba rota sin alternativa. El manejador de
 *     HTMLPurifier para `data:` no es un pase libre: sólo acepta
 *     image/jpeg, image/gif y image/png, decodifica el payload, comprueba el
 *     tipo REAL de los bytes (exif_imagetype/getimagesize, no el mime que
 *     declara la URI) y reescribe la URI a partir de eso — cualquier cosa que
 *     no sea una de esas tres imágenes se descarta entera. dompdf ya tiene
 *     `data://` en allowed_protocols.
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
 *
 * SELECTORES html/body (auditoría 2026-08-06). Filter.ExtractStyleBlocks
 * valida cada selector contra los elementos de HTML.Allowed y descarta la
 * regla entera si no lo reconoce. Como `body` y `html` no son elementos que
 * HTMLPurifier sepa modelar (declararlos en HTML.Allowed lanza "Element 'body'
 * is not supported"), TODA regla `body { … }` desaparecía en silencio — y ahí
 * es donde una plantilla exportada de Word o de otro panel pone su tipografía
 * base: font-family, font-size, márgenes. El PDF salía con los defaults de
 * dompdf (Times 16 px) mientras el editor mostraba la letra del tenant, así
 * que el mismo documento se veía distinto en cada lado sin que nada fallara.
 *
 * La solución es un enmascarado de ida y vuelta: antes de purificar, los
 * selectores `html`/`body` se reescriben como clases (que HTMLPurifier sí
 * acepta) y después se devuelven a su nombre. Las DECLARACIONES pasan por el
 * mismo allowlist de CSS que el resto — no se salta ninguna validación, sólo
 * se esquiva una limitación del validador de selectores.
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
        // id/style/class en todos los tags (auditoría 2026-08-03): plantillas
        // reales de clientes exportadas de WispHub dependen de style inline
        // en <div> (ej. page-break-before) y de selectores CSS por id
        // (ej. #clausulas en <style>) — sin esto, el sanitizer las rompe
        // aunque el HTML/CSS en sí no tenga nada peligroso.
        'div[id|style|class]', 'p[id|style|class]', 'br[id|style]',
        'h1[id|style|class]', 'h2[id|style|class]', 'h3[id|style|class]',
        'h4[id|style|class]', 'h5[id|style|class]', 'h6[id|style|class]',
        'strong[id|style|class]', 'b[id|style|class]', 'em[id|style|class]',
        'i[id|style|class]', 'u[id|style|class]',
        'ul[id|style|class]', 'ol[id|style|class]', 'li[id|style|class]',
        'span[id|style|class]',
        'a[href|id|style|class]',
        // `width` como ATRIBUTO HTML (no CSS) en table/td/th (auditoría
        // 2026-08-04): plantillas reales de WispHub arman su layout de 2
        // columnas con width="50%" como atributo, no style="width:50%" — sin
        // esto, ambas <td> quedaban sin ancho explícito y dompdf las
        // renderizaba con auto-layout, rompiendo la maquetación de columnas
        // por completo. NO se declara en <tr>: verificado que HTMLPurifier lo
        // descarta ahí de todos modos (no es válido en <tr> según su
        // definición de HTML4).
        //
        // `height` NO se declara en ninguno a propósito: fixDompdfPagination-
        // Quirks() lo retira después de toda la familia <table> porque genera
        // páginas en blanco en dompdf (ver su docblock). Sí sigue permitido en
        // <img>, donde es legítimo e inofensivo.
        'table[width|id|style|class]', 'thead[id|style|class]', 'tbody[id|style|class]',
        'tr[id|style|class]',
        'td[width|colspan|rowspan|id|style|class]', 'th[width|colspan|rowspan|id|style|class]',
        'img[src|alt|width|height|id|style|class]',
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
        // Sin esto, el atributo id se descarta SIEMPRE aunque esté declarado
        // en HTML.Allowed (verificado empíricamente 2026-08-03). Necesario
        // para selectores CSS por id (#clausulas) en plantillas reales de
        // clientes. Verificado que HTMLPurifier valida sintaxis del valor
        // (rechaza ids inválidos como "javascript:alert(1)") y fuerza
        // unicidad (un id duplicado se descarta en la 2ª aparición) — no es
        // un bypass, es un atributo no ejecutable con validación real. El
        // riesgo típico de este flag (colisión de id con la página que aloja
        // el contenido) no aplica: el resultado es un PDF standalone vía
        // Pdf::loadHTML(), nunca se embebe en otra página.
        $config->set('Attr.EnableID', true);

        $config->set('Filter.ExtractStyleBlocks', true);
        // Sin Scope: en modo avanzado el tenant es dueño del documento
        // completo, no hay shell fijo que proteger de selectores CSS ajenos.

        // `data` habilita la única imagen que sobrevive hasta el PDF (ver el
        // docblock de la clase): las url http/https nunca se descargan porque
        // dompdf corre con enable_remote=false. HTMLPurifier valida y
        // re-codifica el payload, y sólo para image/jpeg|gif|png.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'data' => true]);
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
        $raw = $this->maskDocumentSelectors((string) $html);

        $body = $this->purifier->purify($raw);
        $body = $this->fixDompdfPaginationQuirks($body);
        $styleBlocks = $this->purifier->context->get('StyleBlocks') ?? [];

        $style = $this->unmaskDocumentSelectors(implode("\n", $styleBlocks));

        return ['body' => $body, 'style' => $style];
    }

    /**
     * Clases con las que se disfrazan `html` y `body` mientras HTMLPurifier
     * valida el CSS. En minúsculas y con guiones a propósito: CSSTidy
     * reformatea el bloque entero y no queremos depender de que respete
     * mayúsculas. Sólo existen entre maskDocumentSelectors() y
     * unmaskDocumentSelectors(); nunca se guardan ni llegan al PDF.
     */
    private const HTML_SELECTOR_MASK = 'ispwatch-doc-html';
    private const BODY_SELECTOR_MASK = 'ispwatch-doc-body';

    /**
     * Reescribe los selectores `html`/`body` de cada bloque <style> como
     * clases, para que el validador de selectores de
     * Filter.ExtractStyleBlocks no tire la regla entera. Ver el docblock de
     * la clase para el porqué.
     *
     * Sólo toca los <style>: un `body` en el contenido (texto, un atributo)
     * no se ve afectado.
     */
    private function maskDocumentSelectors(string $html): string
    {
        return preg_replace_callback(
            '/(<style\b[^>]*>)(.*?)(<\/style\s*>)/is',
            fn (array $m) => $m[1] . $this->maskSelectorsInCss($m[2]) . $m[3],
            $html
        ) ?? $html;
    }

    /**
     * Enmascara sólo la parte de SELECTOR de cada regla, nunca las
     * declaraciones — si no, `font-family: body` (o cualquier valor que
     * contenga esa palabra) se rompería.
     *
     * Se recorre carácter a carácter en vez de con una expresión regular
     * porque hay que distinguir "texto antes de un {" (selector) de "texto
     * antes de un }" (declaraciones), y eso incluye las reglas anidadas
     * dentro de un @media, donde el selector viene después de un `{` y no
     * después de un `}`.
     */
    private function maskSelectorsInCss(string $css): string
    {
        $out = '';
        $buffer = '';

        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '{') {
                $out .= $this->maskSelectorList($buffer) . '{';
                $buffer = '';
            } elseif ($char === '}') {
                $out .= $buffer . '}';
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }

        return $out . $buffer;
    }

    private function maskSelectorList(string $selectors): string
    {
        // El lookbehind evita pisar `.body`, `#body`, `[class="body"]` o
        // `bodycopy`: sólo se cambia el nombre de elemento suelto.
        return preg_replace(
            ['/(?<![\w.#\-"\'=])html(?![\w\-])/i', '/(?<![\w.#\-"\'=])body(?![\w\-])/i'],
            ['.' . self::HTML_SELECTOR_MASK, '.' . self::BODY_SELECTOR_MASK],
            $selectors
        ) ?? $selectors;
    }

    private function unmaskDocumentSelectors(string $css): string
    {
        return str_replace(
            ['.' . self::HTML_SELECTOR_MASK, '.' . self::BODY_SELECTOR_MASK],
            ['html', 'body'],
            $css
        );
    }

    /**
     * Correcciones de paginación específicas de dompdf, aplicadas en UNA sola
     * pasada de DOM sobre el body ya saneado. Ambas nacen del mismo caso real
     * (auditoría 2026-08-04, diagnóstico sobre plantillas exportadas del
     * editor WYSIWYG de WispHub que generaban PDFs con páginas en blanco):
     *
     *   1. page-break-before en el PRIMER elemento del documento. dompdf
     *      inserta una página vacía porque no hay página anterior de la que
     *      "romper" — un navegador simplemente lo ignora ahí. Sólo se retira
     *      en el primer elemento; el resto de saltos del documento se
     *      respetan tal cual los escribió el tenant.
     *
     *   2. Alturas fijas en tablas/celdas (`height` como atributo HTML o como
     *      CSS). En un navegador, `height` en una tabla es un MÍNIMO que se
     *      ignora cuando el contenido crece; dompdf lo trata rígidamente y,
     *      con contenido que desborda, genera páginas en blanco. Medido sobre
     *      un contrato real: quitando SÓLO las alturas, el PDF pasa de 8
     *      páginas con 3 en blanco a 7 con 1 — ninguna otra variante (quitar
     *      los saltos de página, la tabla más ancha que la hoja, o los <div>
     *      anidados) cambiaba nada. Se limita a la familia de <table> a
     *      propósito: en <img> la altura es legítima e inofensiva (una imagen
     *      nunca se parte entre páginas).
     */
    private function fixDompdfPaginationQuirks(string $body): string
    {
        if (trim($body) === '') {
            return $body;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $body . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $bodyEl = $dom->getElementsByTagName('body')->item(0);
        if (!$bodyEl) {
            return $body;
        }

        $this->stripLeadingPageBreak($bodyEl);
        $this->stripTableHeights($dom);

        $inner = '';
        foreach ($bodyEl->childNodes as $node) {
            $inner .= $dom->saveHTML($node);
        }

        return $inner;
    }

    /** Quirk 1: ver fixDompdfPaginationQuirks(). */
    private function stripLeadingPageBreak(\DOMElement $bodyEl): void
    {
        $first = null;
        foreach ($bodyEl->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $first = $child;
                break;
            }
        }

        if (!$first || !$first->hasAttribute('style')) {
            return;
        }

        $this->removeStyleProperty($first, 'page-break-before');
    }

    /** Quirk 2: ver fixDompdfPaginationQuirks(). */
    private function stripTableHeights(\DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//table | //tr | //td | //th');

        foreach ($nodes as $el) {
            /** @var \DOMElement $el */
            $el->removeAttribute('height');
            $this->removeStyleProperty($el, 'height');
        }
    }

    /**
     * Retira una propiedad del atributo style de un elemento, quitando el
     * atributo entero si era la única que tenía.
     */
    private function removeStyleProperty(\DOMElement $el, string $property): void
    {
        if (!$el->hasAttribute('style')) {
            return;
        }

        $original = $el->getAttribute('style');
        // El \b final evita que 'height' arrase también con 'min-height',
        // 'max-height' o 'line-height', que no causan este problema.
        $pattern = '/(?<![\w-])' . preg_quote($property, '/') . '\s*:\s*[^;]+;?/i';
        $cleaned = trim((string) preg_replace($pattern, '', $original));

        if ($cleaned === $original) {
            return;
        }

        if ($cleaned === '') {
            $el->removeAttribute('style');
        } else {
            $el->setAttribute('style', $cleaned);
        }
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
