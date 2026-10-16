<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Frontera de MikroTik (KAN-16): los controladores no hablan con un
 * RouterBoard por su cuenta.
 *
 * La regla de destino es que ningún controlador use `App\Services\MikroTik\*`
 * ni `MikroTikSshService`: todo debe pasar por un servicio de dominio. Esa
 * fachada todavía no existe (paso 1 de la secuencia), y tres controladores
 * llaman hoy directamente a la capa de red. Por eso este test es un
 * TRINQUETE y no la regla final:
 *
 *   - lo que ya está en BASELINE se tolera;
 *   - cualquier referencia NUEVA (otro controlador, u otra clase en uno de
 *     los tres) hace fallar el test;
 *   - si una entrada de BASELINE deja de usarse, el test también falla y
 *     pide borrarla. Así la lista sólo puede encoger.
 *
 * Cuando exista la fachada y se migren estos tres, BASELINE queda vacío y el
 * test pasa a ser la regla completa sin cambiar nada más.
 */
class MikroTikBoundaryTest extends TestCase
{
    /**
     * Deuda medida el 2026-10-05. Controlador (relativo a app/Http/Controllers)
     * => clases de red que usa directamente.
     */
    private const BASELINE = [
        'CustomerProfileController.php' => ['MikroTikSshService'],
        'PlanController.php'            => ['MikroTik\\RouterEndpointResolver', 'MikroTikSshService'],
        'RouterController.php'          => ['MikroTik\\RouterEndpointResolver', 'MikroTik\\SshTunnelManager', 'MikroTikSshService'],
    ];

    /** @return array<string, string[]> */
    private function currentReferences(): array
    {
        $root = dirname(__DIR__, 3) . '/app/Http/Controllers';
        $found = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/App\\\\Services\\\\(MikroTik\\\\\w+|MikroTikSshService)\b/',
                file_get_contents($file->getPathname()),
                $matches
            );

            $classes = array_values(array_unique($matches[1]));
            if ($classes === []) {
                continue;
            }

            sort($classes);
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $found[$relative] = $classes;
        }

        ksort($found);

        return $found;
    }

    public function test_ningun_controlador_nuevo_habla_directamente_con_mikrotik(): void
    {
        $current = $this->currentReferences();
        $nuevas = [];

        foreach ($current as $controller => $classes) {
            $toleradas = self::BASELINE[$controller] ?? [];
            foreach (array_diff($classes, $toleradas) as $class) {
                $nuevas[] = "{$controller} → App\\Services\\{$class}";
            }
        }

        $this->assertSame([], $nuevas,
            "Un controlador usa directamente la capa de red de MikroTik:\n  " . implode("\n  ", $nuevas)
            . "\nPasa por un servicio de dominio (RouterProvisioningService, CustomerProvisioningService…) en vez de importarla."
        );
    }

    public function test_la_lista_de_deuda_solo_puede_encoger(): void
    {
        $current = $this->currentReferences();
        $sobrantes = [];

        foreach (self::BASELINE as $controller => $classes) {
            foreach (array_diff($classes, $current[$controller] ?? []) as $class) {
                $sobrantes[] = "{$controller} → {$class}";
            }
        }

        $this->assertSame([], $sobrantes,
            "Estas entradas de BASELINE ya no se usan; bórralas de MikroTikBoundaryTest:\n  " . implode("\n  ", $sobrantes)
        );
    }

    public function test_el_detector_ve_una_referencia_nueva(): void
    {
        // Que el patrón no se quede ciego: si dejara de reconocer las dos
        // formas de nombrar la clase, los dos tests de arriba pasarían siempre.
        $muestra = "use App\\Services\\MikroTik\\QueueManager;\n\$x = app(\\App\\Services\\MikroTikSshService::class);";
        preg_match_all('/App\\\\Services\\\\(MikroTik\\\\\w+|MikroTikSshService)\b/', $muestra, $m);

        $this->assertSame(['MikroTik\\QueueManager', 'MikroTikSshService'], $m[1]);
    }
}
