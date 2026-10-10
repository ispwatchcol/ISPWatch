<?php

namespace App\Services\Templates;

use App\Models\DocumentTemplate;

/**
 * Explica por qué una plantilla se ve bien pero sale con los datos en
 * blanco. Es la respuesta a la clase entera de reportes tipo "el contrato
 * no toma bien la plantilla" (P-13 / P-3 de docs/MEJORAS_RECOMENDADAS.md).
 *
 * El sistema blanquea en silencio cualquier {{token}} que no reconozca —
 * regla deliberada desde la Fase 1: un typo nunca debe romper el render.
 * El problema no es la regla, es que desde la interfaz "token desconocido"
 * y "el sistema no funciona" se ven exactamente igual. Esta clase NO cambia
 * el render: sólo inspecciona el borrador y devuelve hallazgos para que el
 * endpoint de vista previa los exponga por X-Template-Warnings.
 *
 * Inspecciona el HTML CRUDO que escribió el tenant, antes de sanear: lo que
 * hay que explicarle es lo que él ve en el editor, no lo que quedó después
 * de pasar por el allowlist.
 *
 * Los mensajes se arman aquí y no en el frontend por dos razones: viajan en
 * una cabecera HTTP que ya tiene un formato acordado, y así son verificables
 * por las pruebas de PHP junto con la detección que los origina.
 */
class TemplateDiagnostics
{
    /** Marcador con la sintaxis de otro sistema; existe equivalente conocido. */
    public const KIND_FOREIGN_PLACEHOLDER = 'foreign_placeholder';

    /** Marcador válido, pero de otro tipo de documento (copiar/pegar entre plantillas). */
    public const KIND_WRONG_TYPE = 'wrong_type';

    /** No se reconoce y no se parece a nada del catálogo. */
    public const KIND_UNKNOWN_PLACEHOLDER = 'unknown_placeholder';

    /** Marcador de otro sistema SIN llaves: aquí es texto literal y se imprime tal cual. */
    public const KIND_FOREIGN_MARKER = 'foreign_marker';

    /** <img> apuntando a internet: dompdf corre con enable_remote = false. */
    public const KIND_REMOTE_IMAGE = 'remote_image';

    /** font-family que dompdf no tiene instalada: en el PDF sale con otra letra. */
    public const KIND_UNSUPPORTED_FONT = 'unsupported_font';

    /** Bloque que no se pudo insertar (lo detecta BlockMarkerInjector, no esta clase). */
    public const KIND_ORPHANED_BLOCK = 'orphaned_block';

    /** Llaves desparejadas o basura dentro: no es un marcador, se imprime literal. */
    public const KIND_MALFORMED_PLACEHOLDER = 'malformed_placeholder';

    /** Documento completo editado en modo seguro: el shell fijo lo va a desarmar. */
    public const KIND_NEEDS_ADVANCED_MODE = 'needs_advanced_mode';

    /** Celda de tabla con tanto texto que puede no caber en una página: dompdf recorta lo que sobra. */
    public const KIND_LONG_TABLE_CELL = 'long_table_cell';

    /**
     * Caracteres de texto visible a partir de los cuales una celda se reporta
     * (P-8 / KAN-60). dompdf no parte una celda entre páginas: si no cabe, la
     * empuja entera a la siguiente y DESCARTA en silencio lo que no entra.
     *
     * No se puede saber de verdad si desborda sin renderizar, porque depende del
     * contenido resuelto, del papel y de la letra. Este umbral es la
     * aproximación barata: una página A4 a 10-11 pt lleva unos 4.500-5.000
     * caracteres a todo lo ancho, y la mitad en una columna de media página, que
     * es el caso típico de un contrato maquetado con tablas. El caso medido que
     * originó la tarjeta perdía ~1.800 de unos 17.700. Un aviso de más cuesta
     * una lectura; uno de menos cuesta texto legal fuera de un contrato firmado.
     */
    public const LONG_TABLE_CELL_CHARS = 2500;

    /** Máximo de celdas largas reportadas: el arreglo es el mismo para todas. */
    private const MAX_LONG_TABLE_CELLS = 2;

    /**
     * Tope de hallazgos reportados. Los avisos viajan en una cabecera HTTP
     * (X-Template-Warnings) y una plantilla migrada entera puede tener
     * decenas de marcadores ajenos: sin tope, la respuesta se pasa del
     * límite de cabeceras del proxy (8 KB por defecto en nginx) y el
     * navegador se queda sin el PDF, que es peor que un aviso incompleto.
     */
    public const MAX_FINDINGS = 12;

    /** Máximo de imágenes remotas reportadas: la causa y el arreglo son el mismo para todas. */
    private const MAX_REMOTE_IMAGES = 3;

    /** Ídem para las fuentes: con 2 ejemplos ya se entiende el problema. */
    private const MAX_UNSUPPORTED_FONTS = 2;

    /**
     * Familias que dompdf sí sabe resolver, en minúsculas. Es el listado
     * literal de vendor/dompdf/dompdf/lib/fonts/installed-fonts.dist.json: no
     * hay ninguna otra, dompdf no lee las fuentes del sistema operativo.
     * Cualquier familia fuera de aquí cae al default_font de
     * config/dompdf.php ('serif' → Times-Roman).
     */
    private const DOMPDF_FONT_FAMILIES = [
        'sans-serif', 'times', 'times-roman', 'courier', 'helvetica',
        'zapfdingbats', 'symbol', 'serif', 'monospace', 'fixed',
        'dejavu sans', 'dejavu sans mono', 'dejavu serif',
    ];

    /**
     * Orden de presentación. Primero lo que deja datos en blanco sin ninguna
     * pista visual, al final lo que el tenant ya nota a simple vista.
     */
    private const SEVERITY = [
        // Primero el que hace que el PDF no se parezca en nada a lo que el
        // tenant ve en el editor: no es un marcador mal puesto, es el
        // documento entero que no se va a usar.
        self::KIND_NEEDS_ADVANCED_MODE,
        // Justo después: es texto que DESAPARECE del PDF sin dejar hueco
        // visible, en el documento con valor contractual.
        self::KIND_LONG_TABLE_CELL,
        self::KIND_MALFORMED_PLACEHOLDER,
        self::KIND_FOREIGN_MARKER,
        self::KIND_FOREIGN_PLACEHOLDER,
        self::KIND_WRONG_TYPE,
        self::KIND_UNKNOWN_PLACEHOLDER,
        self::KIND_ORPHANED_BLOCK,
        self::KIND_REMOTE_IMAGE,
        // Al final: no deja nada en blanco ni rompe la maquetación, sólo
        // cambia la letra. Es lo primero que se sacrifica si sobran hallazgos.
        self::KIND_UNSUPPORTED_FONT,
    ];

    private const TYPE_LABELS = [
        DocumentTemplate::TYPE_INVOICE      => 'Factura',
        DocumentTemplate::TYPE_CONTRACT     => 'Contrato',
        DocumentTemplate::TYPE_INSTALLATION => 'Hoja de Instalación',
    ];

    /**
     * Mismo patrón que PlaceholderResolver::apply(), a propósito: lo que
     * aquí se marca como desconocido tiene que ser exactamente lo que allá
     * se blanquea, ni un token más ni uno menos.
     */
    private const TOKEN_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /**
     * @param  bool|null $isAdvancedMode Modo con el que se va a renderizar.
     *                   `null` = no comprobarlo (plantillas base, pruebas del
     *                   catálogo), porque el modo lo decide quien las carga.
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    public function inspect(string $html, string $type, ?bool $isAdvancedMode = null): array
    {
        $findings = array_merge(
            $this->inspectRenderMode($html, $isAdvancedMode),
            $this->inspectPlaceholders($html, $type),
            $this->inspectMalformedPlaceholders($html),
            $this->inspectLiteralMarkers($html, $type),
            $this->inspectRemoteImages($html),
            $this->inspectFonts($html),
            $this->inspectLongTableCells($html),
        );

        return $this->prioritize($findings);
    }

    /**
     * Celdas `<td>`/`<th>` con texto suficiente para no caber en una página
     * (P-8). Es la única pérdida de contenido que el sanitizer no puede corregir
     * solo: convertir a ciegas toda tabla de una celda en `<div>` cambiaría el
     * diseño de plantillas que hoy salen bien. Por eso se avisa, no se toca.
     *
     * Se mide el texto visible con los espacios colapsados, sin etiquetas, y
     * también el de las tablas anidadas: una celda que contiene otra tabla
     * tampoco se parte. Los {{marcadores}} cuentan por su nombre, no por lo que
     * resuelven. Es una cota inferior, y los bloques largos se insertan fuera de
     * las celdas.
     *
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function inspectLongTableCells(string $html): array
    {
        if (stripos($html, '<td') === false && stripos($html, '<th') === false) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $cells = [];
        foreach (['td', 'th'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $cell) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $cell->textContent));
                $length = mb_strlen($text);

                if ($length > self::LONG_TABLE_CELL_CHARS) {
                    $cells[] = ['text' => $text, 'length' => $length];
                }
            }
        }

        // La más larga primero: es la que con más seguridad desborda.
        usort($cells, fn (array $a, array $b) => $b['length'] <=> $a['length']);

        $findings = [];
        foreach (array_slice($cells, 0, self::MAX_LONG_TABLE_CELLS) as $cell) {
            $findings[] = [
                'kind'    => self::KIND_LONG_TABLE_CELL,
                // El inicio del texto, para que el tenant encuentre la celda en el editor.
                'token'   => $this->shorten($cell['text'], 40),
                'label'   => 'Texto largo dentro de una tabla',
                'message' => 'Esta celda tiene unos ' . number_format($cell['length'], 0, ',', '.')
                    . ' caracteres. El PDF no puede partir una celda entre dos páginas: si no cabe, la pasa '
                    . 'entera a la siguiente y corta lo que sobra, sin avisar. Compara el final de esa sección '
                    . 'en la vista previa. Si falta texto, sácalo de la tabla y ponlo en párrafos '
                    . '(un <div> en modo avanzado).',
            ];
        }

        return $findings;
    }

    /**
     * El caso más caro de todos, y el más difícil de deducir desde la
     * interfaz: el tenant edita un documento HTML completo con el modo
     * avanzado APAGADO. El editor visual se lo muestra perfecto —es un
     * navegador, entiende todo—, pero al renderizar, el modo seguro pasa el
     * cuerpo por un allowlist estrecho que **borra anchos, estilos, colores e
     * imágenes**, y lo incrusta dentro del shell fijo del sistema. El PDF que
     * sale no se parece en nada al editor: sale la plantilla base con el
     * contenido del tenant desmaquetado al final.
     *
     * Medido el 2026-08-06 sobre el contrato real de un tenant: en modo
     * avanzado sobrevive el 95 % del documento, en modo seguro el 51 % — y de
     * ese 51 % se pierden TODOS los anchos y colores, que es lo que sostiene
     * un diseño a dos columnas.
     *
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function inspectRenderMode(string $html, ?bool $isAdvancedMode): array
    {
        if ($isAdvancedMode !== false) {
            return [];
        }

        // Un documento completo es la señal inequívoca; las tablas con ancho,
        // los <img> y los <style> son las tres cosas que el modo seguro
        // descarta y que sostienen una maquetación.
        if (!preg_match('/<html[\s>]|<body[\s>]|<!doctype|<style[\s>]|<img[\s>]|<table[^>]*\bwidth\b/i', $html)) {
            return [];
        }

        return [[
            'kind'    => self::KIND_NEEDS_ADVANCED_MODE,
            'token'   => 'Modo avanzado apagado',
            'label'   => 'El PDF no se va a parecer al editor',
            'message' => 'Tu plantilla usa anchos, imágenes o estilos propios, y el modo normal los '
                . 'elimina y mete el resto dentro de la plantilla base del sistema. Activa el modo '
                . 'avanzado para que el PDF salga como lo ves aquí.',
        ]];
    }

    /**
     * Llaves desparejadas (`{{token}`) o con algo que no es parte del nombre
     * dentro (`{{ cliente.cedula&nbsp;}}`). PlaceholderResolver no los
     * reconoce como marcador, así que **no los blanquea: los imprime tal
     * cual** en el PDF. Es un síntoma distinto —"me sale un texto raro" en vez
     * de "me sale vacío"— y por eso no lo veía el escaneo normal, que sólo
     * mira los marcadores bien formados.
     *
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function inspectMalformedPlaceholders(string $html): array
    {
        // Cualquier grupo que empiece por {{ y termine en } o }}, sin llaves
        // por dentro. Los bien formados se descartan después comparándolos con
        // el patrón real del resolver, para no reportar dos veces lo mismo.
        if (!preg_match_all('/\{\{[^{}]*\}{1,2}/', $html, $matches)) {
            return [];
        }

        $findings = [];

        foreach (array_unique($matches[0]) as $candidate) {
            if (preg_match(self::TOKEN_PATTERN, $candidate)) {
                continue;
            }

            $findings[] = [
                'kind'    => self::KIND_MALFORMED_PLACEHOLDER,
                'token'   => $this->shorten($candidate, 40),
                'label'   => 'Marcador mal escrito',
                'message' => 'Le faltan llaves o tiene algo raro dentro, así que no se reconoce como '
                    . 'marcador: se imprime tal cual en el PDF. Debe ser exactamente '
                    . '{{nombre.campo}}, con dos llaves a cada lado.',
            ];
        }

        return $findings;
    }

    /**
     * Mezcla los hallazgos de esta clase con los bloques huérfanos que
     * reporta BlockMarkerInjector vía TemplateRenderer::lastRenderWarnings(),
     * para que todo salga por un solo canal y con un solo tope.
     *
     * @param  string[] $orphanedBlockTokens
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    public function inspectWithOrphanedBlocks(
        string $html,
        string $type,
        array $orphanedBlockTokens,
        ?bool $isAdvancedMode = null
    ): array {
        $blockLabels = config("document_placeholder_blocks.{$type}", []);

        $orphaned = collect($orphanedBlockTokens)
            ->unique()
            ->map(fn (string $token) => [
                'kind'    => self::KIND_ORPHANED_BLOCK,
                'token'   => $token,
                'label'   => $blockLabels[$token] ?? $token,
                'message' => 'No se pudo insertar en esa posición: ponlo en su propio párrafo, '
                    . 'no dentro de un enlace, una tabla comprimida ni texto con formato.',
            ])
            ->all();

        return $this->prioritize(array_merge(
            $this->inspect($html, $type, $isAdvancedMode),
            $orphaned
        ));
    }

    /**
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function inspectPlaceholders(string $html, string $type): array
    {
        if (!preg_match_all(self::TOKEN_PATTERN, $html, $matches)) {
            return [];
        }

        $known = $this->knownTokens($type);
        $aliases = $this->aliasesFor('scalar', $type);
        $findings = [];

        foreach (array_unique($matches[1]) as $token) {
            if (isset($known[$token])) {
                continue;
            }

            if (isset($aliases[$token])) {
                $findings[] = $this->foreignPlaceholder($token, $aliases[$token]);
                continue;
            }

            if ($otherType = $this->typeThatKnows($token, $type)) {
                $findings[] = $this->wrongType($token, $otherType, $type);
                continue;
            }

            $findings[] = $this->unknownPlaceholder($token, array_keys($known));
        }

        return $findings;
    }

    /**
     * Marcadores de otro sistema que no usan la sintaxis {{...}}: aquí no son
     * marcadores de nada, son texto, y se imprimen literalmente en el PDF.
     * Por eso no los ve inspectPlaceholders() y necesitan su propia pasada.
     *
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function inspectLiteralMarkers(string $html, string $type): array
    {
        $findings = [];

        foreach ($this->aliasesFor('literal', $type) as $marker => $suggestion) {
            if (!str_contains($html, $marker)) {
                continue;
            }

            $findings[] = [
                'kind'    => self::KIND_FOREIGN_MARKER,
                'token'   => $marker,
                'label'   => $marker,
                'message' => 'Es un marcador de otro sistema y aquí es texto normal: se imprime tal cual. '
                    . 'Reemplázalo por ' . $this->wrap($suggestion) . '.',
            ];
        }

        return $findings;
    }

    /**
     * dompdf corre con enable_remote = false (config/dompdf.php): nunca hace
     * una petición de red al generar el PDF, así que una imagen enlazada a
     * internet sale rota SIEMPRE, aunque en el editor se vea perfecta. Es la
     * segunda causa del reporte del 2026-08-05 y la más desconcertante,
     * porque la vista previa del editor sí la muestra.
     *
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function inspectRemoteImages(string $html): array
    {
        if (!preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']?(https?:\/\/[^"\'\s>]+)/i', $html, $matches)) {
            return [];
        }

        $findings = [];

        foreach (array_slice(array_unique($matches[1]), 0, self::MAX_REMOTE_IMAGES) as $url) {
            $findings[] = [
                'kind'    => self::KIND_REMOTE_IMAGE,
                'token'   => $this->shorten($url),
                'label'   => 'Imagen enlazada desde internet',
                'message' => 'Las imágenes enlazadas a una dirección de internet no se descargan al generar '
                    . 'el PDF y salen rotas. Sube el logo en «Marca en los documentos» y usa '
                    . $this->wrap('empresa.logo') . '.',
            ];
        }

        return $findings;
    }

    /**
     * dompdf NO lee las fuentes del sistema operativo: sólo conoce las 14
     * fuentes base del PDF y las DejaVu que trae empaquetadas
     * (self::DOMPDF_FONT_FAMILIES). Una plantilla exportada de Word o de otro
     * panel suele venir con `font-family: Calibri` o `Arial`, y ahí dompdf se
     * cae al default (Times-Roman) — con otro ancho de letra, así que el texto
     * ocupa distinto y los saltos de página caen en otro sitio. En el editor
     * se ve con la fuente correcta porque el navegador sí la tiene: es la
     * clase de diferencia editor↔PDF que no se puede deducir mirando.
     *
     * Sólo se reporta si NINGUNA familia de la lista es reconocible: una pila
     * como `Calibri, Arial, sans-serif` sí funciona (dompdf recorre la lista y
     * se queda con `sans-serif`), y avisar de ella sería ruido.
     *
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function inspectFonts(string $html): array
    {
        if (!preg_match_all('/font-family\s*:\s*([^;{}"\']+)/i', $html, $matches)) {
            return [];
        }

        $findings = [];

        foreach (array_unique($matches[1]) as $declaration) {
            $families = array_filter(array_map(
                fn (string $family) => trim($family, " \t\n\r\0\x0B'\"" ),
                explode(',', $declaration)
            ));

            if ($families === [] || $this->hasResolvableFont($families)) {
                continue;
            }

            $findings[] = [
                'kind'    => self::KIND_UNSUPPORTED_FONT,
                'token'   => $this->shorten(implode(', ', $families), 40),
                'label'   => 'Fuente que el PDF no tiene',
                'message' => 'En el editor se ve con esa letra, pero el PDF no la tiene instalada y la '
                    . 'reemplaza por Times, que es más angosta: el texto ocupa distinto y los saltos de '
                    . 'página se mueven. Agrega al final una de las que sí existen '
                    . '(serif, sans-serif, monospace, Times, Helvetica, Courier, DejaVu Sans, DejaVu Serif), '
                    . 'por ejemplo «' . $families[array_key_first($families)] . ', sans-serif».',
            ];

            if (count($findings) >= self::MAX_UNSUPPORTED_FONTS) {
                break;
            }
        }

        return $findings;
    }

    /**
     * Espejo de Dompdf\FontMetrics::getFont(): compara en minúsculas y sin
     * comillas contra el catálogo exacto, igual que hace dompdf.
     *
     * @param string[] $families
     */
    private function hasResolvableFont(array $families): bool
    {
        foreach ($families as $family) {
            if (in_array(strtolower($family), self::DOMPDF_FONT_FAMILIES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{kind:string,token:string,label:string,message:string}
     */
    private function foreignPlaceholder(string $token, string $suggestion): array
    {
        return [
            'kind'    => self::KIND_FOREIGN_PLACEHOLDER,
            'token'   => $token,
            'label'   => 'Marcador de otro sistema',
            'message' => 'No se reconoce y sale en blanco. Aquí el equivalente es ' . $this->wrap($suggestion) . '.',
        ];
    }

    /**
     * @return array{kind:string,token:string,label:string,message:string}
     */
    private function wrongType(string $token, string $otherType, string $currentType): array
    {
        return [
            'kind'    => self::KIND_WRONG_TYPE,
            'token'   => $token,
            'label'   => 'Marcador de ' . (self::TYPE_LABELS[$otherType] ?? $otherType),
            'message' => 'Existe, pero sólo en la plantilla de ' . (self::TYPE_LABELS[$otherType] ?? $otherType)
                . '. En ' . (self::TYPE_LABELS[$currentType] ?? $currentType) . ' sale en blanco.',
        ];
    }

    /**
     * @param  string[] $knownTokens
     * @return array{kind:string,token:string,label:string,message:string}
     */
    private function unknownPlaceholder(string $token, array $knownTokens): array
    {
        $closest = $this->closestKnownToken($token, $knownTokens);

        return [
            'kind'    => self::KIND_UNKNOWN_PLACEHOLDER,
            'token'   => $token,
            'label'   => 'Marcador desconocido',
            'message' => $closest
                ? 'No se reconoce y sale en blanco. ¿Querías escribir ' . $this->wrap($closest) . '?'
                : 'No se reconoce y sale en blanco. Revisa la lista de marcadores disponibles arriba.',
        ];
    }

    /**
     * Sugerencia por cercanía para el typo genuino (`{{cliente.telefno}}`).
     * El umbral es corto a propósito: una sugerencia equivocada manda al
     * tenant a cambiar el marcador que sí estaba bien, y eso cuesta más que
     * no sugerir nada.
     *
     * @param  string[] $knownTokens
     */
    private function closestKnownToken(string $token, array $knownTokens): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $limit = max(1, (int) floor(strlen($token) * 0.34));

        foreach ($knownTokens as $known) {
            $distance = levenshtein(strtolower($token), strtolower($known));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $known;
            }
        }

        return $bestDistance <= min(3, $limit) ? $best : null;
    }

    /**
     * El tipo de documento cuyo catálogo sí contiene el token, o null si no
     * lo conoce ninguno. Cubre el caso de copiar/pegar entre plantillas de
     * distinto tipo, que en la práctica es el error más frecuente.
     */
    private function typeThatKnows(string $token, string $currentType): ?string
    {
        foreach (DocumentTemplate::TYPES as $type) {
            if ($type === $currentType) {
                continue;
            }

            if (isset($this->knownTokens($type)[$token])) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Catálogo cerrado del tipo: escalares + bloques, exactamente lo que
     * PlaceholderResolver y BlockPlaceholderResolver saben resolver.
     *
     * @return array<string,string> token => descripción
     */
    private function knownTokens(string $type): array
    {
        return array_merge(
            config("document_placeholders.{$type}", []),
            config("document_placeholder_blocks.{$type}", [])
        );
    }

    /**
     * @return array<string,string> alias => token de ISPwatch
     */
    private function aliasesFor(string $group, string $type): array
    {
        return array_merge(
            config("document_placeholder_aliases.{$group}.common", []),
            config("document_placeholder_aliases.{$group}.{$type}", [])
        );
    }

    /**
     * Ordena por severidad y aplica el tope. El tope se aplica DESPUÉS de
     * ordenar para que, cuando sobren hallazgos, los que se pierdan sean los
     * cosméticos y no los que dejan el documento sin datos.
     *
     * @param  array<int,array{kind:string,token:string,label:string,message:string}> $findings
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    private function prioritize(array $findings): array
    {
        $seen = [];
        $unique = [];
        foreach ($findings as $finding) {
            $key = $finding['kind'] . '|' . $finding['token'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $finding;
        }

        usort($unique, function (array $a, array $b) {
            $rankA = array_search($a['kind'], self::SEVERITY, true);
            $rankB = array_search($b['kind'], self::SEVERITY, true);

            return ($rankA === false ? PHP_INT_MAX : $rankA) <=> ($rankB === false ? PHP_INT_MAX : $rankB);
        });

        return array_slice($unique, 0, self::MAX_FINDINGS);
    }

    private function wrap(string $token): string
    {
        return '{{' . $token . '}}';
    }

    private function shorten(string $url, int $max = 60): string
    {
        return strlen($url) <= $max ? $url : substr($url, 0, $max - 1) . '…';
    }
}
