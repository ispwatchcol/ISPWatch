<?php

namespace Tests\Feature\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-25 / KAN-78: alternar una capa del Mapa de Clientes no mueve la cámara.
 *
 * No hay entorno de navegador en la suite, así que se fija en la fuente lo que
 * decide el comportamiento: dos vigilantes distintos, y sólo el de clientes
 * reencuadra. Si alguien los vuelve a juntar en `watch([filteredCustomers,
 * layers])`, la cámara vuelve a saltar al encuadre general en cada capa.
 */
class CustomerMapRefitTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(resource_path('js/pages/CustomerMap.vue'));
    }

    #[Test]
    public function alternar_capas_redibuja_sin_reencuadrar(): void
    {
        $this->assertMatchesRegularExpression(
            '/watch\(\s*layers,\s*\(\)\s*=>\s*\{\s*applyLayers\(\{\s*refit:\s*false\s*\}\);/',
            $this->source()
        );
    }

    #[Test]
    public function cambiar_los_clientes_visibles_si_reencuadra(): void
    {
        $this->assertMatchesRegularExpression(
            '/watch\(\s*filteredCustomers,\s*\(\)\s*=>\s*\{\s*applyLayers\(\{\s*refit:\s*true\s*\}\);/',
            $this->source()
        );
    }

    #[Test]
    public function ya_no_hay_un_vigilante_unico_para_clientes_y_capas(): void
    {
        $this->assertDoesNotMatchRegularExpression('/watch\(\s*\[\s*filteredCustomers\s*,\s*layers\s*\]/', $this->source());
    }

    #[Test]
    public function el_fit_depende_de_refit(): void
    {
        $this->assertStringContainsString('if (refit && hasBounds && !suppressNextFit)', $this->source());
    }
}
