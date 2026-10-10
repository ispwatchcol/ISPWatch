<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada pago con su forma de pago del catálogo (KAN-109).
 *
 * `payments.method` era la única referencia a la forma de pago, y es un texto
 * copiado del catálogo en el momento de registrar. Renombrar la forma de pago
 * en el catálogo no tocaba los pagos: el filtro por el nombre nuevo dejaba
 * fuera todo lo cobrado antes, y el modal de edición abría con el select vacío
 * invitando a "arreglarlo" eligiendo cualquier cosa.
 *
 * La referencia estable es ahora `payments.payment_method_id`. El texto se
 * CONSERVA tal cual como constancia de con qué nombre se registró el pago:
 * nadie lo reescribe, ni el relleno ni un renombrado.
 *
 * El criterio de emparejamiento vive sólo aquí —lo usan la migración, el
 * comando `payments:link-methods` y el alta/edición de pagos— para que no
 * pueda divergir entre quien rellena lo histórico y quien registra lo nuevo:
 *
 *  - mismo tenant, siempre (el catálogo es por tenant);
 *  - nombre igual ignorando mayúsculas y espacios en los extremos;
 *  - la coincidencia tiene que ser ÚNICA. Si el tenant tiene dos formas de
 *    pago que normalizan igual, el pago no se enlaza a ninguna: elegir una
 *    sería inventar a cuál se refería el cajero.
 *
 * Lo que no empareja se queda sin enlace y con su texto intacto ("método
 * histórico"). En particular, un pago registrado con un nombre que después se
 * renombró en el catálogo NO se enlaza solo: no queda rastro de qué nombre
 * viejo corresponde a qué forma de pago actual, y adivinarlo cambiaría la
 * contabilidad de ese pago.
 */
class PaymentMethodLinker
{
    public const MATCH     = 'enlazado';
    public const NO_MATCH  = 'sin_coincidencia';
    public const AMBIGUOUS = 'ambiguo';
    public const NO_TENANT = 'sin_tenant';

    /** Clave de comparación de un nombre: sin espacios en los extremos y en minúsculas (Unicode). */
    public static function normalize(?string $name): string
    {
        return mb_strtolower(trim((string) $name), 'UTF-8');
    }

    /**
     * Id de la forma de pago del tenant cuyo nombre coincide con `$name`, o null
     * si no hay ninguna o hay más de una.
     */
    public function matchByName(?int $tenantId, ?string $name): ?int
    {
        $key = self::normalize($name);

        if (!$tenantId || $key === '') {
            return null;
        }

        $ids = $this->catalogIndex([$tenantId])[$tenantId][$key] ?? [];

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Forma de pago del catálogo por id, sólo si es del tenant indicado.
     *
     * Consulta sin el global scope de tenant a propósito: el pago puede estar
     * procesándose desde consola (facturación de instalaciones, jobs) donde no
     * hay sesión, y el tenant correcto es el del PAGO, no el de quien mira.
     *
     * @return object{id:int,name:string}|null
     */
    public function findInTenant(?int $tenantId, int $paymentMethodId): ?object
    {
        if (!$tenantId) {
            return null;
        }

        return DB::table('payment_methods')
            ->where('tenant_id', $tenantId)
            ->where('id', $paymentMethodId)
            ->first(['id', 'name']);
    }

    /**
     * Qué pasaría con los pagos sin enlazar, agrupados por tenant y texto. No escribe.
     *
     * Funciona también ANTES de la migración (sin la columna todavía): así el
     * comando puede dar el reporte previo al despliegue que pide KAN-109.
     *
     * @return Collection<int, array{tenant_id:?int, method:string, payments:int, result:string, payment_method_id:?int, payment_method_name:?string}>
     */
    public function plan(?int $onlyTenantId = null): Collection
    {
        $query = DB::table('payments')
            ->select('tenant_id', 'method', DB::raw('count(*) as total'))
            ->groupBy('tenant_id', 'method')
            ->orderBy('tenant_id')
            ->orderBy('method');

        if (Schema::hasColumn('payments', 'payment_method_id')) {
            $query->whereNull('payment_method_id');
        }

        if ($onlyTenantId) {
            $query->where('tenant_id', $onlyTenantId);
        }

        $groups = $query->get();

        $tenantIds = $groups->pluck('tenant_id')->filter()->unique()->values()->all();
        $index     = $this->catalogIndex($tenantIds);
        $names     = DB::table('payment_methods')->whereIn('tenant_id', $tenantIds ?: [0])->pluck('name', 'id');

        return $groups->map(function ($g) use ($index, $names) {
            $tenantId = $g->tenant_id !== null ? (int) $g->tenant_id : null;
            $ids      = $tenantId ? ($index[$tenantId][self::normalize($g->method)] ?? []) : [];

            $result = match (true) {
                $tenantId === null => self::NO_TENANT,
                count($ids) === 1  => self::MATCH,
                count($ids) > 1    => self::AMBIGUOUS,
                default            => self::NO_MATCH,
            };

            $matchedId = $result === self::MATCH ? $ids[0] : null;

            return [
                'tenant_id'           => $tenantId,
                'method'              => (string) $g->method,
                'payments'            => (int) $g->total,
                'result'              => $result,
                'payment_method_id'   => $matchedId,
                'payment_method_name' => $matchedId ? $names[$matchedId] ?? null : null,
            ];
        })->values();
    }

    /**
     * Aplica el plan: rellena `payment_method_id` en los pagos que emparejan.
     *
     * Sólo escribe la columna nueva y sólo donde está vacía, así que correrlo
     * dos veces no cambia nada y no pisa un enlace ya hecho a mano. No toca
     * `method` ni `updated_at`: el pago no cambió, sólo se le puso la referencia.
     *
     * Una sentencia por (tenant, forma de pago), no por pago: con la base en
     * otra región cada ida y vuelta cuesta ~250 ms (BITÁCORA § 60), y esto corre
     * dentro de la migración.
     *
     * @return int Pagos enlazados.
     */
    public function apply(Collection $plan): int
    {
        $linked = 0;

        $plan->where('result', self::MATCH)
            ->groupBy(fn ($row) => $row['tenant_id'] . ':' . $row['payment_method_id'])
            ->each(function (Collection $rows) use (&$linked) {
                $first = $rows->first();

                $linked += DB::table('payments')
                    ->where('tenant_id', $first['tenant_id'])
                    ->whereNull('payment_method_id')
                    ->whereIn('method', $rows->pluck('method')->all())
                    ->update(['payment_method_id' => $first['payment_method_id']]);
            });

        return $linked;
    }

    /**
     * Catálogo indexado por tenant y nombre normalizado. Cada clave guarda una
     * LISTA de ids, porque la tabla no impide los duplicados: el alta los
     * rechaza, pero el auto-sembrado de `index()` no tiene candado y dos
     * primeras visitas simultáneas siembran dos veces.
     *
     * @param  int[]  $tenantIds
     * @return array<int, array<string, int[]>>
     */
    private function catalogIndex(array $tenantIds): array
    {
        $index = [];

        if (!$tenantIds) {
            return $index;
        }

        DB::table('payment_methods')
            ->whereIn('tenant_id', $tenantIds)
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'name'])
            ->each(function ($m) use (&$index) {
                $index[(int) $m->tenant_id][self::normalize($m->name)][] = (int) $m->id;
            });

        return $index;
    }
}
