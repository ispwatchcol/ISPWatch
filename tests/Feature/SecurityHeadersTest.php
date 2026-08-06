<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La CSP se fija con pruebas porque una regresión aquí NO rompe nada de forma
 * visible en el servidor: falla en el navegador del usuario, en silencio. Pasó
 * con `frame-src`: al no declararlo, heredaba `default-src 'self'` y el
 * navegador rechazaba el `<iframe src="blob:...">` de la vista previa en PDF
 * de la hoja de instalación — el visor salía gris con un icono de documento
 * roto, sin ningún error en los logs de la aplicación.
 */
class SecurityHeadersTest extends TestCase
{
    private function csp(): string
    {
        // Cualquier ruta sirve: el middleware es global.
        return $this->get('/api/user')->headers->get('Content-Security-Policy') ?? '';
    }

    public function test_allows_framing_pdf_blobs_generated_by_the_app(): void
    {
        $this->assertStringContainsString("frame-src 'self' blob:", $this->csp());
    }

    /**
     * `data:` en frame-src sí es un vector clásico de XSS: si alguien lo añade
     * "para que también funcionen los data URIs", este test lo frena.
     */
    public function test_does_not_allow_framing_data_uris(): void
    {
        $csp = $this->csp();

        $frameSrc = collect(explode(';', $csp))
            ->map(fn ($d) => trim($d))
            ->first(fn ($d) => str_starts_with($d, 'frame-src'));

        $this->assertNotNull($frameSrc, 'La CSP debe declarar frame-src explícitamente.');
        $this->assertStringNotContainsString('data:', $frameSrc);
    }

    /**
     * Las tres restricciones que la auditoría de 2026-07-30 dejó activas y que
     * no deben relajarse al tocar la cabecera por otros motivos.
     */
    public function test_keeps_the_hardened_directives(): void
    {
        $csp = $this->csp();

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);

        $scriptSrc = collect(explode(';', $csp))
            ->map(fn ($d) => trim($d))
            ->first(fn ($d) => str_starts_with($d, 'script-src'));

        $this->assertNotNull($scriptSrc);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
    }
}
