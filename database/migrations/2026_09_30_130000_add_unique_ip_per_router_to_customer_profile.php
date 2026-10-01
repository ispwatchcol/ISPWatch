<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice único parcial: dos clientes del MISMO router no pueden compartir IP
 * (KAN-118). Espejo de 2026_07_17_000330, que hace lo mismo con el usuario
 * PPPoE.
 *
 * La regla ya la validan store(), update() y la carga masiva, pero sólo en la
 * aplicación: dos guardados concurrentes —o cualquier escritor que no pase por
 * esas validaciones— podían duplicarla. Una IP repetida en el mismo router no
 * es un dato feo: es un corte o una reconexión que cae sobre el abonado
 * equivocado, y un AAA externo que no sabe a cuál de los dos atribuirle la
 * sesión.
 *
 * Parcial (sin router, sin IP o con IP vacía no cuenta) por la misma razón que
 * el de PPPoE: el retiro de un cliente libera su IP dejándola en null, y una
 * restricción sin condición bloquearía casos reales.
 *
 * Antes de crear el índice se buscan duplicados; si los hay, aborta listando
 * los pares en vez de fallar con el error crudo de PostgreSQL. Qué cliente
 * cambia de IP es una decisión del ISP, no de una migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::select($this->duplicateCheckQuery(DB::connection()->getDriverName()));

        if (!empty($duplicates)) {
            $detail = collect($duplicates)
                ->map(fn ($row) => "router_id={$row->router_id} ip_user=\"{$row->ip_user}\" customer_ids=[{$row->customer_ids}]")
                ->implode(' | ');

            throw new \RuntimeException(
                'No se puede crear el índice único de IP por router: ya existen duplicados. '
                . 'Cambia manualmente la IP de uno de los clientes en conflicto y vuelve a migrar. '
                . 'Duplicados encontrados: ' . $detail
            );
        }

        DB::statement('
            CREATE UNIQUE INDEX customer_profile_ip_user_router_unique
            ON customer_profile (router_id, ip_user)
            WHERE ip_user IS NOT NULL AND ip_user != \'\' AND router_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_profile_ip_user_router_unique');
    }

    private function duplicateCheckQuery(string $driver): string
    {
        $customerIds = match ($driver) {
            'pgsql'  => "string_agg(user_id::text, ', ')",
            'sqlite' => "group_concat(user_id, ', ')",
            default  => throw new \RuntimeException(
                "No se puede ejecutar esta migración: driver de base de datos '{$driver}' no soportado."
            ),
        };

        return "
            SELECT router_id, ip_user, COUNT(*) AS cnt,
                   {$customerIds} AS customer_ids
            FROM customer_profile
            WHERE ip_user IS NOT NULL AND ip_user != ''
              AND router_id IS NOT NULL
            GROUP BY router_id, ip_user
            HAVING COUNT(*) > 1
        ";
    }
};
