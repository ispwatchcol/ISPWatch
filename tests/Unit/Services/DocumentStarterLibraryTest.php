<?php

namespace Tests\Unit\Services;

use App\Models\DocumentTemplate;
use App\Services\Templates\DocumentStarterLibrary;
use App\Services\Templates\TemplateDiagnostics;
use Tests\TestCase;

class DocumentStarterLibraryTest extends TestCase
{
    private DocumentStarterLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = new DocumentStarterLibrary();
    }

    public function test_every_document_type_offers_at_least_one_starter(): void
    {
        foreach (DocumentTemplate::TYPES as $type) {
            $this->assertNotEmpty(
                $this->library->listFor($type),
                "El tipo {$type} se quedó sin plantilla base: el editor volvería a abrir en blanco."
            );
        }
    }

    public function test_the_listing_never_carries_the_body(): void
    {
        foreach ($this->library->listFor('contract') as $starter) {
            $this->assertArrayNotHasKey('body_html', $starter);
            $this->assertSame(
                ['slug', 'name', 'description', 'advanced', 'page_size', 'page_orientation'],
                array_keys($starter)
            );
        }
    }

    public function test_find_returns_the_body_of_a_catalogued_starter(): void
    {
        $starter = $this->library->find('contract', 'crc-colombia');

        $this->assertNotNull($starter);
        $this->assertStringContainsString('<!DOCTYPE html>', $starter['body_html']);
        $this->assertStringContainsString('{{contrato.numero}}', $starter['body_html']);
        $this->assertTrue($starter['advanced']);
        // El formato CRC es a dos columnas: en vertical no cabe y sale
        // descuadrado, así que la plantilla trae su propio papel.
        $this->assertSame('landscape', $starter['page_orientation']);
    }

    public function test_an_unknown_slug_returns_null_instead_of_reading_the_disk(): void
    {
        $this->assertNull($this->library->find('contract', 'no-existe'));
    }

    /**
     * El slug es entrada del usuario y llega por la URL. Sólo se convierte en
     * ruta DESPUÉS de existir en el catálogo; si se concatenara directo, esto
     * leería un archivo arbitrario del servidor.
     */
    public function test_a_traversal_slug_cannot_reach_a_file_outside_the_catalogue(): void
    {
        $this->assertNull($this->library->find('contract', '../../../.env'));
        $this->assertNull($this->library->find('contract', '../invoice/ispwatch-basica'));
    }

    /**
     * Una plantilla base con un marcador que ISPwatch no resuelve entregaría
     * al tenant un documento con datos en blanco desde el primer día — el
     * problema exacto que el diagnóstico existe para evitar.
     */
    public function test_no_starter_uses_a_placeholder_the_system_cannot_resolve(): void
    {
        $diagnostics = new TemplateDiagnostics();

        foreach (DocumentTemplate::TYPES as $type) {
            foreach ($this->library->listFor($type) as $meta) {
                $starter = $this->library->find($type, $meta['slug']);
                $findings = $diagnostics->inspect($starter['body_html'], $type);

                $this->assertSame(
                    [],
                    $findings,
                    "La plantilla base {$type}/{$meta['slug']} tiene marcadores inválidos: "
                        . implode(', ', array_column($findings, 'token'))
                );
            }
        }
    }
}
