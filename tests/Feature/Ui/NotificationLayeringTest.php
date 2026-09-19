<?php

namespace Tests\Feature\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Un aviso se tiene que poder LEER por encima del modal que lo provocó.
 *
 * EL FALLO QUE ESTO CIERRA
 *
 * Al intentar archivar un ticket con un cargo sin anular, el backend responde
 * 422 con un mensaje que nombra la factura a anular. El mensaje salía, pero
 * DETRÁS del modal de archivado: el contenedor de notificaciones estaba en
 * `z-[100]` y ese modal en `z-[9999]`. Es decir, la interfaz se quedaba muda
 * justo en el único momento en que tenía algo que decir.
 *
 * La causa de fondo no era el número sino que no había ninguna escala: cada
 * pantalla montaba su propio `<NotificationToast>` —37 contenedores potenciales,
 * cada uno con su `z-index`— y los modales se declaraban con `z-50` en unos
 * sitios y `z-[9999]` en otros. Subir el número del toast lo habría tapado
 * hasta el siguiente modal con un número más alto.
 *
 * Estas pruebas fijan la estructura, no el número: un contenedor único, una
 * escala con nombre, y el escalón de los avisos por encima del de los modales.
 */
class NotificationLayeringTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    private function lee(string $ruta): string
    {
        return file_get_contents(resource_path($ruta));
    }

    /** Valor de `z-index` declarado para una clase de la escala. */
    private function escalon(string $clase): int
    {
        $ok = preg_match(
            '/\.' . preg_quote($clase, '/') . '\s*\{[^}]*z-index:\s*(\d+)/',
            $this->css(),
            $m,
        );

        $this->assertSame(1, $ok, "La escala no declara `.{$clase}` en resources/css/app.css.");

        return (int) $m[1];
    }

    #[Test]
    public function la_escala_de_apilamiento_existe_y_pone_los_avisos_encima(): void
    {
        $modal = $this->escalon('z-app-modal');
        $toast = $this->escalon('z-app-toast');

        // Lo único que importa de verdad: el orden. Los números concretos
        // pueden cambiar; que un aviso quede debajo de un modal, no.
        $this->assertGreaterThan(
            $modal,
            $toast,
            'Un aviso SIEMPRE va por encima del modal que lo provoca; si no, la interfaz '
            . 'se queda muda justo cuando tiene algo que decir.',
        );

        $this->assertGreaterThan($this->escalon('z-app-dropdown'), $modal);
    }

    #[Test]
    public function el_contenedor_de_avisos_se_monta_una_sola_vez_en_la_raiz(): void
    {
        $app = $this->lee('js/App.vue');

        $this->assertStringContainsString('<NotificationHost />', $app);
        $this->assertStringContainsString("import NotificationHost from './components/NotificationHost.vue'", $app);

        // Fuera del `router-view`: si viviera dentro, un cambio de página
        // desmontaría el aviso que acaba de aparecer.
        $this->assertGreaterThan(
            strpos($app, '<router-view />'),
            strpos($app, '<NotificationHost />'),
        );
    }

    #[Test]
    public function el_host_es_el_unico_contenedor_y_usa_el_escalon_de_avisos(): void
    {
        $host = $this->lee('js/components/NotificationHost.vue');

        $this->assertStringContainsString('z-app-toast', $host);
        $this->assertStringContainsString('Teleport to="body"', $host);

        // `NotificationToast` pasó a ser un adaptador: ya no pinta contenedor.
        // Si volviera a hacerlo tendríamos 37 otra vez.
        $toast = $this->lee('js/components/NotificationToast.vue');

        $this->assertStringNotContainsString('fixed top-4', $toast);
        $this->assertStringNotContainsString('class="fixed', $toast);
        $this->assertStringContainsString('useNotifications', $toast);
    }

    #[Test]
    public function el_adaptador_conserva_la_api_que_usan_las_pantallas(): void
    {
        $toast = $this->lee('js/components/NotificationToast.vue');

        // 37 pantallas hacen `toast.value?.success(...)`. Si el adaptador
        // dejara de exponer uno de estos métodos, fallarían en silencio: son
        // llamadas con `?.` y no revientan, simplemente no avisan de nada.
        foreach (['success', 'error', 'warning', 'info', 'clear'] as $metodo) {
            $this->assertStringContainsString("{$metodo}:", $toast, "El adaptador debe exponer `{$metodo}`.");
        }
    }

    #[Test]
    public function ningun_modal_declara_ya_un_z_index_suelto(): void
    {
        $sueltos = [];

        foreach ($this->archivosVue() as $archivo) {
            $contenido = file_get_contents($archivo);

            // Se ignoran los comentarios, que citan el valor viejo al explicar
            // por qué se retiró.
            $sinComentarios = preg_replace('/<!--.*?-->/s', '', $contenido);
            $sinComentarios = preg_replace('#//.*$#m', '', $sinComentarios);

            if (preg_match('/class="[^"]*\bz-\[\d{3,}\]/', $sinComentarios)) {
                $sueltos[] = str_replace(resource_path(), '', $archivo);
            }
        }

        $this->assertSame(
            [],
            $sueltos,
            "Estos componentes vuelven a declarar un z-index alto a mano, que es como se abrió "
            . "el agujero: el número de al lado no se ve desde donde se escribe. Usa la escala "
            . "con nombre de resources/css/app.css.\n" . implode("\n", $sueltos),
        );
    }

    #[Test]
    public function el_aviso_se_puede_cerrar_a_mano_y_no_corta_el_mensaje(): void
    {
        $host = $this->lee('js/components/NotificationHost.vue');

        $this->assertStringContainsString('aria-label="Cerrar notificación"', $host);

        // Un mensaje de validación del backend puede nombrar un número de
        // factura. Truncarlo deja al operador sin el único dato que necesitaba.
        $this->assertStringContainsString('break-words', $host);
        $this->assertStringNotContainsString('truncate', $host);

        // Móvil: ocupa el ancho disponible en lugar de salirse por la derecha.
        $this->assertStringContainsString('inset-x-4', $host);
        $this->assertStringContainsString('sm:max-w-sm', $host);

        // Y desaparece solo: los errores duran más porque hay que leerlos.
        $composable = $this->lee('js/composables/useNotifications.js');

        $this->assertMatchesRegularExpression('/error:\s*(\d{4,})/', $composable);
        preg_match('/error:\s*(\d+)/', $composable, $err);
        preg_match('/success:\s*(\d+)/', $composable, $ok);

        $this->assertGreaterThanOrEqual((int) $ok[1], (int) $err[1]);
        $this->assertLessThanOrEqual(30000, (int) $err[1], 'Un aviso que se queda medio minuto estorba.');
    }

    /** @return array<int, string> */
    private function archivosVue(): array
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        $archivos = [];

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'vue') {
                $archivos[] = $f->getPathname();
            }
        }

        sort($archivos);

        return $archivos;
    }
}
