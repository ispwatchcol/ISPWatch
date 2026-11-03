<?php

namespace Tests\Unit\Support;

use App\Support\TicketMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR #7 · F1-19. Los percentiles se fijan contra un conjunto conocido y contra
 * el método de Excel (`PERCENTILE.INC`), que es con el que el cliente los va a
 * recalcular sobre la exportación.
 */
class TicketMetricsTest extends TestCase
{
    #[Test]
    public function los_percentiles_coinciden_con_percentile_inc_de_excel(): void
    {
        $valores = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

        // =PERCENTILE.INC(A1:A10; 0,5) → 5,5 · 0,9 → 9,1 · 0,95 → 9,55
        $this->assertEqualsWithDelta(5.5, TicketMetrics::percentil($valores, 0.5), 1e-9);
        $this->assertEqualsWithDelta(9.1, TicketMetrics::percentil($valores, 0.9), 1e-9);
        $this->assertEqualsWithDelta(9.55, TicketMetrics::percentil($valores, 0.95), 1e-9);
        $this->assertEqualsWithDelta(1.0, TicketMetrics::percentil($valores, 0), 1e-9);
        $this->assertEqualsWithDelta(10.0, TicketMetrics::percentil($valores, 1), 1e-9);
    }

    #[Test]
    public function el_orden_de_entrada_no_cambia_el_resultado(): void
    {
        $this->assertEqualsWithDelta(
            TicketMetrics::percentil([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.9),
            TicketMetrics::percentil([10, 3, 7, 1, 9, 2, 8, 4, 6, 5], 0.9),
            1e-9,
        );
    }

    #[Test]
    public function sin_datos_no_hay_percentil_y_con_uno_es_ese_valor(): void
    {
        $this->assertNull(TicketMetrics::percentil([], 0.9));
        $this->assertSame(42.0, TicketMetrics::percentil([42], 0.95));
    }

    #[Test]
    public function un_percentil_fuera_de_rango_es_un_error_de_programacion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TicketMetrics::percentil([1, 2], 90);
    }

    #[Test]
    public function el_resumen_recibe_minutos_y_devuelve_horas(): void
    {
        // Diez tickets de 1 a 10 horas.
        $minutos = array_map(fn (int $h) => $h * 60, range(1, 10));

        $this->assertSame([
            'count'        => 10,
            'avg_hours'    => 5.5,
            'median_hours' => 5.5,
            'p90_hours'    => 9.1,
            'p95_hours'    => 9.55,
        ], TicketMetrics::resumen($minutos));
    }

    #[Test]
    public function el_promedio_esconde_la_cola_y_el_p90_no(): void
    {
        // Nueve tickets de 2 h y uno de 20 días: el caso que motiva el § 17.
        $minutos = array_merge(array_fill(0, 9, 120), [20 * 24 * 60]);

        $resumen = TicketMetrics::resumen($minutos);

        $this->assertSame(2.0, $resumen['median_hours']);
        $this->assertGreaterThan(40, $resumen['avg_hours']);
        $this->assertGreaterThan(40, $resumen['p90_hours']);
    }

    #[Test]
    public function el_resumen_vacio_no_inventa_ceros(): void
    {
        $this->assertSame([
            'count' => 0, 'avg_hours' => null, 'median_hours' => null,
            'p90_hours' => null, 'p95_hours' => null,
        ], TicketMetrics::resumen([]));
    }
}
