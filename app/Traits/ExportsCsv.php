<?php

namespace App\Traits;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de listados a CSV, en streaming.
 *
 * Los tres listados de Finanzas exportan **todo el filtro aplicado**, no la
 * página visible, así que el resultado puede ser mucho más grande que cualquier
 * respuesta normal: se escribe fila a fila sobre la salida y la consulta se
 * recorre en lotes (`lazy()`), sin materializar el conjunto completo en memoria.
 *
 * Dos detalles de formato que existen porque el destino real de estos archivos
 * es Excel en un equipo con configuración regional de Colombia:
 *
 *  - **Separador `;`** — con configuración es-CO el separador de lista de
 *    Windows es `;`. Un CSV separado por comas se abre con todas las columnas
 *    apelmazadas en una sola, que es exactamente lo que un contador reporta como
 *    "el export está roto".
 *  - **BOM UTF-8** — sin él, Excel interpreta el archivo en ANSI y las tildes y
 *    las eñes salen como `Ã³` / `Ã±`.
 *
 * Los importes se formatean con coma decimal (`number_format(..., ',', '')`) por
 * la misma razón: `50000.00` se lee como texto en un Excel es-CO, `50000,00` se
 * lee como número y suma.
 */
trait ExportsCsv
{
    /**
     * @param string   $filename  Nombre sugerido de descarga (incluye .csv)
     * @param string[] $columns   Cabeceras, en el mismo orden que devuelve $mapRow
     * @param mixed    $query     Consulta ya filtrada y ordenada (se recorre con lazy())
     * @param callable $mapRow    fn($modelo): array — una fila del CSV
     */
    protected function streamCsv(string $filename, array $columns, $query, callable $mapRow): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $query, $mapRow) {
            $out = fopen('php://output', 'w');

            // BOM: hace que Excel abra el archivo como UTF-8 y respete las tildes.
            fwrite($out, "\xEF\xBB\xBF");

            $this->putCsvRow($out, $columns);

            // lazy() recorre en lotes y aplica los eager loads por lote: con
            // cursor() cada relación dispararía una consulta por fila.
            foreach ($query->lazy(500) as $model) {
                $this->putCsvRow($out, $mapRow($model));
            }

            fclose($out);
        }, $filename, [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /** Escribe una fila con el separador que espera Excel en español. */
    private function putCsvRow($handle, array $fields): void
    {
        // El `escape` vacío deja un CSV conforme a RFC 4180 (comillas dobladas)
        // en vez del escape con backslash propio de PHP, que Excel no entiende.
        fputcsv($handle, $fields, ';', '"', '');
    }

    /** Importe con coma decimal, sin separador de miles: legible por Excel es-CO. */
    protected function csvMoney($value): string
    {
        return number_format((float) $value, 2, ',', '');
    }

    /** Fecha en ISO corto (YYYY-MM-DD), estable y ordenable en cualquier locale. */
    protected function csvDate($value): string
    {
        if (!$value) {
            return '';
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }
}
