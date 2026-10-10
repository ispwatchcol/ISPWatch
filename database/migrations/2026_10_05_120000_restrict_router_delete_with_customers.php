<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * customer_profile.router_id → router(id) pasa de ON DELETE SET NULL a
 * ON DELETE RESTRICT (P-FK-1 / KAN-55).
 *
 * `RouterController::destroy()` ya rechaza con 409 el borrado de un router con
 * clientes vivos, pero la base seguía aceptando dejarlos huérfanos ante un
 * `DELETE FROM router` por SQL directo (consola de Supabase, un script de
 * mantenimiento). Con RESTRICT, ese borrado se vuelve estructuralmente
 * imposible, y la validación de la aplicación queda como lo que debe ser: el
 * mensaje legible, no la única defensa. El camino `force` (sólo bajas) suelta
 * esas filas a mano dentro de la misma transacción, antes del DELETE.
 *
 * SÓLO POSTGRESQL. En SQLite cambiar una FK obliga a reconstruir la tabla, y
 * customer_profile lleva índices parciales creados con SQL crudo
 * (2026_07_17_000330, 2026_09_30_130000) que esa reconstrucción pondría en
 * riesgo. Las pruebas de SQLite cubren el camino de la aplicación; la FK la
 * verifica el job de PostgreSQL del CI.
 *
 * El nombre de la restricción NO se supone. El esquema de producción tiene
 * deriva respecto de las migraciones (p. ej. `users.is_superadmin` no la crea
 * ninguna), así que se buscan en el catálogo TODAS las FK de
 * customer_profile(router_id) hacia router, se eliminan y se crea una sola con
 * nombre conocido. Si hubiera filas huérfanas, el ADD CONSTRAINT falla y la
 * migración se revierte entera: no se aplica a medias.
 *
 * Se dejan como están, a propósito:
 *  - suspension_action_logs / billing_action_logs: SET NULL. Son historial, y
 *    tienen que sobrevivir al borrado del router.
 *  - ip_assignment: SET NULL. Con RESTRICT, un router con asignaciones dejaría
 *    de poder borrarse por la aplicación, que hoy no las limpia. Ese cambio de
 *    comportamiento merece su propia decisión.
 */
return new class extends Migration
{
    private const NAME = 'customer_profile_router_id_foreign';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceForeignKey('RESTRICT');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceForeignKey('SET NULL');
    }

    private function replaceForeignKey(string $onDelete): void
    {
        $existing = DB::select(<<<'SQL'
            SELECT con.conname
              FROM pg_constraint con
              JOIN pg_class rel       ON rel.oid = con.conrelid
              JOIN pg_namespace nsp   ON nsp.oid = rel.relnamespace
              JOIN pg_class ref       ON ref.oid = con.confrelid
              JOIN pg_attribute att   ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
             WHERE con.contype = 'f'
               AND nsp.nspname = current_schema()
               AND rel.relname = 'customer_profile'
               AND ref.relname = 'router'
               AND att.attname = 'router_id'
        SQL);

        foreach ($existing as $row) {
            DB::statement('ALTER TABLE customer_profile DROP CONSTRAINT ' . $this->quote($row->conname));
        }

        DB::statement(sprintf(
            'ALTER TABLE customer_profile ADD CONSTRAINT %s FOREIGN KEY (router_id) REFERENCES router(id) ON DELETE %s',
            $this->quote(self::NAME),
            $onDelete
        ));
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};
