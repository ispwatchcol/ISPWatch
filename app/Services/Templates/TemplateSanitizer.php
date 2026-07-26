<?php

namespace App\Services\Templates;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side sanitizer for tenant-authored template HTML (Quill output).
 *
 * Applied both when a tenant saves a template (Phase 2) and again at render
 * time (defense in depth). Deliberately excludes <img>, <script>, <iframe>,
 * inline event handlers and non-http(s) URI schemes: templates are meant for
 * text/formatting, not for embedding remote content, which would otherwise
 * open a dompdf remote-fetch/SSRF surface. Branding (logo) is handled as a
 * separate, non-freeform tenant field instead.
 */
class TemplateSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u',
            'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4',
            'span[style]',
            'a[href]',
            'table', 'thead', 'tbody', 'tr', 'td', 'th',
        ]));
        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'text-align']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('Cache.SerializerPath', $this->cacheDir());

        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitize(?string $html): string
    {
        return $this->purifier->purify((string) $html);
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
