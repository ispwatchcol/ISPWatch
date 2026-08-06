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

    /** Bloque que no se pudo insertar (lo detecta BlockMarkerInjector, no esta clase). */
    public const KIND_ORPHANED_BLOCK = 'orphaned_block';

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

    /**
     * Orden de presentación. Primero lo que deja datos en blanco sin ninguna
     * pista visual, al final lo que el tenant ya nota a simple vista.
     */
    private const SEVERITY = [
        self::KIND_FOREIGN_MARKER,
        self::KIND_FOREIGN_PLACEHOLDER,
        self::KIND_WRONG_TYPE,
        self::KIND_UNKNOWN_PLACEHOLDER,
        self::KIND_ORPHANED_BLOCK,
        self::KIND_REMOTE_IMAGE,
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
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    public function inspect(string $html, string $type): array
    {
        $findings = array_merge(
            $this->inspectPlaceholders($html, $type),
            $this->inspectLiteralMarkers($html, $type),
            $this->inspectRemoteImages($html),
        );

        return $this->prioritize($findings);
    }

    /**
     * Mezcla los hallazgos de esta clase con los bloques huérfanos que
     * reporta BlockMarkerInjector vía TemplateRenderer::lastRenderWarnings(),
     * para que todo salga por un solo canal y con un solo tope.
     *
     * @param  string[] $orphanedBlockTokens
     * @return array<int,array{kind:string,token:string,label:string,message:string}>
     */
    public function inspectWithOrphanedBlocks(string $html, string $type, array $orphanedBlockTokens): array
    {
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
            $this->inspect($html, $type),
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
