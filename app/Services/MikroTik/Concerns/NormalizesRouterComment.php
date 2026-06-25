<?php

namespace App\Services\MikroTik\Concerns;

use Illuminate\Support\Str;

/**
 * Normalises customer-name comments before they are pushed to MikroTik.
 *
 * RouterOS does not render accents or "ñ" reliably inside comments, so the
 * customer name is transliterated to ASCII (ñ→n, í→i, ü→u, Á→A, …) first.
 *
 * This is applied ONLY to comments (cosmetic labels). It is NEVER applied to
 * passwords or usernames — those must stay byte-for-byte or auth/lookups break.
 */
trait NormalizesRouterComment
{
    /**
     * Transliterate any value to a router-safe ASCII string.
     */
    protected function asciiRouterComment(string $value): string
    {
        return Str::ascii($value);
    }

    /**
     * Resolve the comment (falling back to $default when empty) and
     * transliterate it to ASCII so the router stores a clean label.
     */
    protected function normalizeRouterComment(?string $comment, string $default = 'ISPWatch Auto'): string
    {
        $comment = ($comment !== null && trim($comment) !== '') ? trim($comment) : $default;

        return $this->asciiRouterComment($comment);
    }
}
