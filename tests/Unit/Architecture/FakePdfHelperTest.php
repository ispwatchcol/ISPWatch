<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P-14 / KAN-65: el doble de dompdf se construye en un solo sitio,
 * `Tests\TestCase::fakePdf()`, donde está escrito por qué necesita
 * `shouldIgnoreMissing(self)`. Un mock de `PDF::class` hecho a mano vuelve a
 * caer en la trampa del `__call()` en cuanto alguien use otro método del
 * wrapper.
 */
class FakePdfHelperTest extends TestCase
{
    public function test_ningun_test_construye_el_mock_de_dompdf_a_mano(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php'
                || str_ends_with($path, '/tests/TestCase.php')
                || str_ends_with($path, '/FakePdfHelperTest.php')) {
                continue;
            }

            if (preg_match('/Mockery::mock\(\s*\\\\?Barryvdh\\\\DomPDF\\\\PDF::class/', file_get_contents($path))) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders,
            "Usa \$this->fakePdf() en vez de mockear PDF a mano:\n  " . implode("\n  ", $offenders));
    }
}
