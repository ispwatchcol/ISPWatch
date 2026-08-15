<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolución código ⇄ id de los catálogos del ticket.
 *
 * FASE 1 · R2 — es la pieza que permite al resto de la aplicación dejar de leer
 * las columnas enum sin llenarse de joins ni de consultas por fila.
 *
 * POR QUÉ UN SINGLETON DE PETICIÓN Y NO UN CACHÉ ESTÁTICO
 *
 * Los siete catálogos juntos son una docena de filas que no cambian durante una
 * petición, así que cargarlos una vez y resolver en memoria evita tanto el N+1
 * de `$ticket->status` como los tres eager loads por consulta que costaría
 * hacerlo con relaciones.
 *
 * Se registra como singleton del CONTENEDOR, no como propiedad estática: el
 * contenedor se reconstruye en cada test, mientras que una estática sobrevive a
 * `RefreshDatabase` y devolvería ids de una base que ya no existe. Es un modo de
 * fallo especialmente desagradable porque sólo aparece a partir del segundo test
 * del archivo.
 *
 * POR QUÉ NO SE FILTRA POR VIGENCIA AL RESOLVER
 *
 * `code()` e `id()` resuelven contra el catálogo COMPLETO, incluidas las filas
 * retiradas. Es deliberado: un ticket de hace dos años tiene que poder seguir
 * diciendo en qué estado quedó aunque ese estado ya no se ofrezca. La vigencia
 * sólo filtra lo que se OFRECE para elegir, y para eso está `vigentes()`.
 */
class TicketCatalogs
{
    /** Catálogos que la aplicación resuelve por código. */
    public const STATUS   = 'ticket_status';
    public const PRIORITY = 'ticket_priority';
    public const CATEGORY = 'ticket_category';

    /** @var array<string, Collection<int, object>> tabla => filas ya cargadas */
    private array $cargados = [];

    /** Código estable de una fila, o null si no hay id. */
    public function code(string $tabla, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->filas($tabla)->firstWhere('id', $id)?->code;
    }

    /** Id de la fila cuyo código coincide, o null si el código no existe. */
    public function id(string $tabla, ?string $code): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        $fila = $this->filas($tabla)->firstWhere('code', $code);

        return $fila ? (int) $fila->id : null;
    }

    /** Etiqueta visible de una fila. */
    public function label(string $tabla, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->filas($tabla)->firstWhere('id', $id)?->label;
    }

    /**
     * Ids de varios códigos a la vez. Los códigos desconocidos se descartan.
     *
     * Devolver una lista más corta que la pedida es intencional: se usa para
     * construir `whereIn`, y un null colado ahí no filtraría nada.
     *
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    public function ids(string $tabla, array $codes): array
    {
        return array_values(array_filter(
            array_map(fn (string $code) => $this->id($tabla, $code), $codes),
        ));
    }

    /**
     * Filas que hoy se pueden elegir, ordenadas para presentación.
     *
     * Vigente = ya empezó y todavía no se retiró. Es la ÚNICA definición de
     * vigencia del sistema; no existe un `is_active` que pueda contradecirla.
     */
    public function vigentes(string $tabla): Collection
    {
        // Se compara como cadena y no como Carbon: el query builder devuelve
        // estos campos en texto, y `'2026-08-14 10:00:00' <= $carbon` no es una
        // comparación de fechas sino de una cadena contra un objeto. En formato
        // `Y-m-d H:i:s` el orden lexicográfico y el cronológico coinciden, que
        // es justo lo que hace segura la comparación textual.
        $ahora = now()->toDateTimeString();

        return $this->filas($tabla)
            ->filter(fn ($fila) => (string) $fila->valid_from <= $ahora
                && ($fila->valid_until === null || (string) $fila->valid_until > $ahora))
            ->sortBy([['weight', 'asc'], ['code', 'asc']])
            ->values();
    }

    /**
     * Códigos vigentes. Alimenta las reglas de validación, de modo que retirar
     * una fila del catálogo deja de aceptarla en la API sin tocar código.
     *
     * @return array<int, string>
     */
    public function codigosVigentes(string $tabla): array
    {
        return $this->vigentes($tabla)->pluck('code')->all();
    }

    /** Versión declarada del catálogo, para que un integrador cachee. */
    public function version(string $catalogo): int
    {
        return (int) (DB::table('ticket_catalog_version')
            ->where('catalog', $catalogo)
            ->value('version') ?? 1);
    }

    /**
     * Vacía lo cargado. Sólo hace falta cuando algo modifica un catálogo
     * dentro de la misma petición —hoy, únicamente los tests.
     */
    public function flush(?string $tabla = null): void
    {
        if ($tabla === null) {
            $this->cargados = [];

            return;
        }

        unset($this->cargados[$tabla]);
    }

    /** Carga perezosa: una consulta por catálogo y por petición. */
    private function filas(string $tabla): Collection
    {
        return $this->cargados[$tabla] ??= DB::table($tabla)
            ->select('id', 'code', 'label', 'weight', 'valid_from', 'valid_until')
            ->get();
    }
}
