<?php

namespace App\Services\Templates;

use DOMDocument;
use DOMDocumentFragment;
use DOMText;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Substitutes block placeholders (trusted, pre-rendered HTML fragments —
 * App\Services\Templates\BlockPlaceholderResolver) into a tenant's ALREADY
 * SANITIZED template, without ever running that trusted HTML back through
 * TemplateSanitizer (which would strip it — e.g. <img> is forbidden in the
 * tenant-facing allowlist on purpose) and without ever doing a raw
 * string-replace of trusted markup into arbitrary tenant-chosen positions
 * (which could land inside an HTML attribute value and corrupt it).
 *
 * Three-phase design (auditoría 2026-07-30 / 2026-07-31):
 *   1. Every {{block.token}} still present in the (sanitized) HTML is
 *      replaced by a single opaque, high-entropy marker (Str::random(), i.e.
 *      random_bytes()-backed — never uniqid()/timestamp-derived) — one
 *      marker PER TOKEN, reused for EVERY occurrence of that same token,
 *      matching how scalar placeholders already behave (same token = same
 *      value everywhere, no matter how many times it's used).
 *   2. The marked-up HTML is parsed into a DOMDocument and every *text node*
 *      (never attribute values — those are structurally unreachable by a
 *      text-node walk) containing a marker gets that marker spliced out and
 *      replaced with real DOM nodes built from the trusted fragment. Because
 *      the marker is reused across occurrences, this can and does splice the
 *      same token's fragment into more than one text node.
 *   3. Any marker occurrence that survives to the final string — because it
 *      only ever appeared inside an attribute value, or a specific
 *      occurrence otherwise wasn't reachable by the text-node walk, even if
 *      OTHER occurrences of that same token were spliced fine — is blanked
 *      to '' (never left visible) and logged as an orphaned block
 *      placeholder, so misuse is visible without ever breaking the render.
 */
class BlockMarkerInjector
{
    /**
     * Convenience wrapper around markify()+splice() for callers (and tests)
     * that don't need scalar substitution to happen in between the two, and
     * don't need the orphaned-token diagnostics splice() also returns.
     *
     * @param array<string,string> $blockValues token => trusted HTML fragment
     */
    public function inject(string $html, array $blockValues, ?int $tenantId, string $documentType): string
    {
        [$marked, $markers] = $this->markify($html, $blockValues);
        [$html, ] = $this->splice($marked, $markers, $tenantId, $documentType);

        return $html;
    }

    /**
     * Fase 1 only: replace every {{block.token}} still present in $html with
     * an opaque, high-entropy marker (Str::random(), random_bytes()-backed —
     * never uniqid()/timestamp-derived), one marker per token.
     *
     * MUST run before PlaceholderResolver::apply() in TemplateRenderer's
     * pipeline: apply()'s regex matches any {{...}} pattern and blanks
     * whatever it doesn't recognize as a scalar value, so a block token
     * would otherwise get wiped out before this class ever saw it. Once a
     * block token is a marker, it no longer looks like {{...}} to apply(),
     * so scalar substitution can safely run around it untouched.
     *
     * @param array<string,string> $blockValues token => trusted HTML fragment
     * @return array{0:string,1:array<string,array{token:string,fragment:string}>}
     */
    public function markify(string $html, array $blockValues): array
    {
        $markers = [];
        foreach ($blockValues as $token => $fragment) {
            if (!str_contains($html, '{{' . $token . '}}')) {
                continue;
            }

            $marker = 'BLOCKMARK_' . Str::random(32);
            $markers[$marker] = ['token' => $token, 'fragment' => $fragment];
            $html = str_replace('{{' . $token . '}}', $marker, $html);
        }

        return [$html, $markers];
    }

    /**
     * Fase 2+3: DOM-based splice of every marker produced by markify(),
     * followed by cleanup of anything left over (never left visible) and a
     * log entry for any marker that never reached a text node.
     *
     * @param array<string,array{token:string,fragment:string}> $markers
     * @return array{0:string,1:string[]} [html, tokens huérfanos (sin duplicados)]
     */
    public function splice(string $html, array $markers, ?int $tenantId, string $documentType): array
    {
        if (empty($markers)) {
            return [$html, []];
        }

        // Fase 2: DOM-based splice — every reachable (content-position)
        // occurrence of every marker gets replaced, independent of how many
        // other occurrences of the same marker were already spliced.
        $html = $this->spliceIntoDom($html, $markers);

        // Fase 3: cleanup + visibility. If a marker is STILL present after
        // the DOM splice, at least one of its occurrences never reached a
        // text node (attribute value, or otherwise unreachable) — blank it
        // (never show the raw marker), log it, and report it back to the
        // caller (TemplateRenderer::lastRenderWarnings()) so the preview
        // endpoint can surface an explicit warning instead of a silent gap.
        $orphaned = [];
        foreach ($markers as $marker => $meta) {
            if (str_contains($html, $marker)) {
                $html = str_replace($marker, '', $html);
                $orphaned[] = $meta['token'];

                Log::warning("Placeholder de bloque '{$meta['token']}' no se pudo insertar como contenido en al menos una posición (¿quedó dentro de un atributo?).", [
                    'tenant_id'     => $tenantId,
                    'document_type' => $documentType,
                    'token'         => $meta['token'],
                ]);
            }
        }

        return [$html, $orphaned];
    }

    /**
     * @param array<string,array{token:string,fragment:string}> $markers
     */
    private function spliceIntoDom(string $html, array $markers): string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            // Defensive only: the fixed wrapper above always yields a <body>.
            return $html;
        }

        $xpath = new DOMXPath($dom);
        // Snapshot up front: DOMXPath::query() returns a static list, so it's
        // safe to mutate the tree (remove/insert nodes) while iterating it.
        $textNodes = $xpath->query('.//text()', $body);

        foreach ($textNodes as $textNode) {
            $this->replaceMarkersInTextNode($dom, $textNode, $markers);
        }

        $inner = '';
        foreach ($body->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        return $inner;
    }

    /**
     * Handles a text node containing zero, one, or several markers — the
     * same token repeated, different tokens, or both — in a single pass,
     * splitting the original text node's value on every known marker at
     * once and rebuilding it as an interleaved sequence of
     * [text, fragment, text, fragment, ...]. Every occurrence found here
     * gets spliced, regardless of whether the same marker was already
     * spliced in a previous text node.
     *
     * @param array<string,array{token:string,fragment:string}> $markers
     */
    private function replaceMarkersInTextNode(DOMDocument $dom, DOMText $textNode, array $markers): void
    {
        $value = $textNode->nodeValue;

        $candidates = [];
        foreach ($markers as $marker => $meta) {
            if (str_contains($value, $marker)) {
                $candidates[] = $marker;
            }
        }

        if (empty($candidates)) {
            return;
        }

        $pattern = '/(' . implode('|', array_map('preg_quote', $candidates)) . ')/';
        $segments = preg_split($pattern, $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        $parent = $textNode->parentNode;
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (isset($markers[$segment])) {
                // Un fragmento vacío (ej. {{empresa.logo}} sin logo subido,
                // {{contrato.firma_cliente}} antes de firmar) es un caso
                // válido y frecuente, no un error — pero insertBefore() con
                // un DocumentFragment sin hijos dispara un warning de PHP
                // ("Document Fragment is empty"), descubierto end-to-end
                // 2026-08-04. Saltarlo es equivalente a insertar "nada".
                $fragmentHtml = $markers[$segment]['fragment'];
                if ($fragmentHtml !== '') {
                    $parent->insertBefore($this->buildFragmentNodes($dom, $fragmentHtml), $textNode);
                }
            } else {
                $parent->insertBefore(new DOMText($segment), $textNode);
            }
        }

        $parent->removeChild($textNode);
    }

    private function buildFragmentNodes(DOMDocument $dom, string $fragmentHtml): DOMDocumentFragment
    {
        $fragment = $dom->createDocumentFragment();

        $fragDom = new DOMDocument();
        libxml_use_internal_errors(true);
        $fragDom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $fragmentHtml . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        foreach ($fragDom->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $fragment->appendChild($dom->importNode($node, true));
        }

        return $fragment;
    }
}
