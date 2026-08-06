<?php

namespace App\Services\Templates;

/**
 * Catálogo de plantillas base con las que el tenant puede EMPEZAR, en vez de
 * encontrarse el editor en blanco.
 *
 * El sistema siempre tuvo un formato base (resources/views/documents/*.blade.php,
 * el que se usa cuando no hay plantilla personalizada), pero vivía en Blade con
 * acceso a objetos ($invoice->total) y por tanto no era editable ni mostrable en
 * el editor. Estas son versiones en HTML plano con marcadores {{...}}, más los
 * formatos regulados que un ISP colombiano necesita.
 *
 * Los cuerpos NO son vistas Blade a propósito: Blade interpretaría {{marcador}}
 * como una expresión PHP y reventaría al compilar. Se leen del disco tal cual.
 */
class DocumentStarterLibrary
{
    /**
     * Metadatos de las plantillas base del tipo, SIN el cuerpo — se listan en
     * cada carga del editor y los cuerpos pesan varios KB cada uno.
     *
     * @return array<int,array{slug:string,name:string,description:string,advanced:bool,page_size:string,page_orientation:string}>
     */
    public function listFor(string $type): array
    {
        return collect(config("document_template_starters.{$type}", []))
            ->filter(fn (array $starter) => $this->pathFor($type, $starter['slug']) !== null)
            ->map(fn (array $starter) => [
                'slug'             => $starter['slug'],
                'name'             => $starter['name'],
                'description'      => $starter['description'],
                'advanced'         => (bool) $starter['advanced'],
                'page_size'        => $starter['page_size'],
                'page_orientation' => $starter['page_orientation'],
            ])
            ->values()
            ->all();
    }

    /**
     * Metadatos + cuerpo, o null si el slug no está en el catálogo del tipo.
     *
     * El slug NUNCA se usa para construir la ruta sin antes existir en el
     * catálogo: es entrada del usuario y concatenarla a un path directamente
     * sería un salto de directorio (`../../.env`).
     *
     * @return array{slug:string,name:string,description:string,advanced:bool,page_size:string,page_orientation:string,body_html:string}|null
     */
    public function find(string $type, string $slug): ?array
    {
        $starter = collect(config("document_template_starters.{$type}", []))
            ->firstWhere('slug', $slug);

        if (!$starter) {
            return null;
        }

        $path = $this->pathFor($type, $slug);
        if ($path === null) {
            return null;
        }

        return [
            'slug'             => $starter['slug'],
            'name'             => $starter['name'],
            'description'      => $starter['description'],
            'advanced'         => (bool) $starter['advanced'],
            'page_size'        => $starter['page_size'],
            'page_orientation' => $starter['page_orientation'],
            'body_html'        => (string) file_get_contents($path),
        ];
    }

    /**
     * Ruta en disco del cuerpo, o null si el archivo no existe — un slug
     * catalogado sin archivo se omite de la lista en vez de romper el editor
     * al cargarlo.
     */
    private function pathFor(string $type, string $slug): ?string
    {
        $path = resource_path("document-starters/{$type}/{$slug}.html");

        return is_file($path) ? $path : null;
    }
}
