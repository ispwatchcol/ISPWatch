<?php

namespace App\Support;

/**
 * Métricas de tiempo del módulo de tickets (PR #7 · F1-19 / F1-20).
 *
 * La Solicitud Maestra (§ 17) pide «mediana, P90 y P95 además del promedio».
 * El promedio solo esconde la cola: diez tickets de 2 horas y uno de 20 días
 * promedian casi dos días, y ninguno de los once tardó eso. Por eso cada tiempo
 * se reporta con su distribución, no con un número.
 *
 * Todo aquí es puro —recibe números y devuelve números— para que el cálculo se
 * pueda fijar con un conjunto conocido sin base de datos.
 */
final class TicketMetrics
{
    /**
     * Zona en la que se PRESENTA y se CORTA por día/mes (F1-20).
     *
     * El almacenamiento sigue en UTC (`config/app.php`), que es lo correcto: lo
     * que cambia con la zona es dónde empieza «hoy» y «este mes». Un ticket
     * radicado el 31 a las 21:00 en Bogotá es del 1 en UTC; contarlo en el mes
     * siguiente es exactamente el error que F1-20 pide evitar.
     */
    public const ZONA = 'America/Bogota';

    /**
     * Percentil por interpolación lineal entre rangos (el método
     * `PERCENTILE.INC` de Excel / `numpy.percentile` por defecto).
     *
     * Se eligió este y no el de «rango más cercano» porque es el que obtiene el
     * cliente si recalcula el valor en una hoja de cálculo con la exportación:
     * dos cifras distintas para el mismo P90 serían una discusión innecesaria.
     *
     * @param  array<int, int|float>  $valores
     * @param  float  $p  Entre 0 y 1 (0.9 = P90).
     */
    public static function percentil(array $valores, float $p): ?float
    {
        if ($valores === []) {
            return null;
        }

        if ($p < 0 || $p > 1) {
            throw new \InvalidArgumentException("El percentil va de 0 a 1; llegó {$p}.");
        }

        $ordenados = array_values($valores);
        sort($ordenados, SORT_NUMERIC);

        $rango = $p * (count($ordenados) - 1);
        $abajo = (int) floor($rango);
        $arriba = (int) ceil($rango);

        $base = (float) $ordenados[$abajo];

        return $base + ($rango - $abajo) * ((float) $ordenados[$arriba] - $base);
    }

    /**
     * Resumen de una distribución de duraciones expresadas en MINUTOS,
     * devuelto en HORAS con dos decimales.
     *
     * Minutos de entrada porque es la resolución útil de un ticket (los
     * segundos son ruido); horas de salida porque es la unidad en que se lee un
     * tiempo de atención —«2,5 h», no «150 min» ni «0,1 días»—.
     *
     * @param  array<int, int|float>  $minutos
     * @return array{count: int, avg_hours: ?float, median_hours: ?float, p90_hours: ?float, p95_hours: ?float}
     */
    public static function resumen(array $minutos): array
    {
        $horas = fn (?float $m) => $m === null ? null : round($m / 60, 2);

        $n = count($minutos);

        return [
            'count'        => $n,
            'avg_hours'    => $horas($n ? array_sum($minutos) / $n : null),
            'median_hours' => $horas(self::percentil($minutos, 0.5)),
            'p90_hours'    => $horas(self::percentil($minutos, 0.9)),
            'p95_hours'    => $horas(self::percentil($minutos, 0.95)),
        ];
    }
}
