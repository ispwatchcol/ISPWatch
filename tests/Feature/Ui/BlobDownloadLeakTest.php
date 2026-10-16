<?php

namespace Tests\Feature\Ui;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * KAN-52 (P-9 de Finanzas, punto 3): el patrón «crear un <a>, hacerle click y
 * olvidarlo» retenía cada blob descargado en memoria hasta recargar la página.
 * Las descargas pasan por `resources/js/utils/download.js`, que sí limpia.
 *
 * Esta guarda impide que vuelva: crear un object URL sólo se admite en los
 * archivos de la lista, y cada uno tiene que revocarlo.
 */
class BlobDownloadLeakTest extends TestCase
{
    /** Archivos que crean object URLs a propósito (y los revocan). */
    private const ALLOWED = [
        'utils/download.js',                                // la utilidad de descarga
        'utils/image.js',                                   // compresión de fotos
        'components/import/ErrorsModal.vue',                // ya revocaba
        'pages/Customers.vue',                              // ya revocaba
        'components/settings/DocumentTemplatesSection.vue', // vista previa del PDF
        'pages/InstallationDetail.vue',                     // vista previa del PDF
    ];

    /** @return array<string,string> ruta relativa a resources/js => contenido */
    private function sourcesUsingObjectUrls(): array
    {
        $root = resource_path('js');
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (str_contains($content, 'createObjectURL')) {
                $found[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $content;
            }
        }

        return $found;
    }

    public function test_las_descargas_nuevas_pasan_por_download_blob(): void
    {
        $fuera = array_values(array_diff(array_keys($this->sourcesUsingObjectUrls()), self::ALLOWED));

        $this->assertSame([], $fuera,
            "Crean object URLs por su cuenta; usa downloadBlob() de @/utils/download:\n  " . implode("\n  ", $fuera));
    }

    public function test_quien_crea_un_object_url_lo_revoca(): void
    {
        foreach ($this->sourcesUsingObjectUrls() as $path => $content) {
            $this->assertStringContainsString('revokeObjectURL', $content, "{$path} crea un object URL y nunca lo revoca.");
        }
    }
}
