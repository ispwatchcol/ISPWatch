<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice único parcial: dos clientes del MISMO router no pueden compartir
 * pppoe_username. Red de seguridad a nivel de BD detrás de la validación de
 * Laravel (StoreCustomerRequest / CustomerProfileController::update()) —
 * cubre condiciones de carrera (dos requests concurrentes pasando la
 * validación de aplicación al mismo tiempo) y cualquier otro escritor directo
 * (ej. importador CSV) que no pase por esas validaciones.
 *
 * Es parcial (WHERE ... IS NOT NULL) para no bloquear filas sin router o sin
 * pppoe_username, y para no repetir el problema del precedente revertido en
 * 2026_05_16_120000/2026_05_16_130000 (unique(['name','last_name']) sin
 * condición, que bloqueó casos reales). Antes de crear el índice se verifica
 * que no existan duplicados; si los hay, aborta con un mensaje explícito en
 * vez de fallar con el error crudo de Postgres, listando los pares para que
 * se decida manualmente (decisión de negocio) cuál de los dos clientes debe
 * renombrar su pppoe_username.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::select("
            SELECT router_id, pppoe_username, COUNT(*) AS cnt,
                   string_agg(user_id::text, ', ') AS customer_ids
            FROM customer_profile
            WHERE pppoe_username IS NOT NULL AND pppoe_username != ''
              AND router_id IS NOT NULL
            GROUP BY router_id, pppoe_username
            HAVING COUNT(*) > 1
        ");

        if (!empty($duplicates)) {
            $detail = collect($duplicates)
                ->map(fn ($row) => "router_id={$row->router_id} pppoe_username=\"{$row->pppoe_username}\" customer_ids=[{$row->customer_ids}]")
                ->implode(' | ');

            throw new \RuntimeException(
                'No se puede crear el índice único de pppoe_username por router: ya existen duplicados. '
                . 'Renombra manualmente el pppoe_username de uno de los clientes en conflicto y vuelve a migrar. '
                . 'Duplicados encontrados: ' . $detail
            );
        }

        DB::statement('
            CREATE UNIQUE INDEX customer_profile_pppoe_username_router_unique
            ON customer_profile (router_id, pppoe_username)
            WHERE pppoe_username IS NOT NULL AND pppoe_username != \'\' AND router_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_profile_pppoe_username_router_unique');
    }
};
