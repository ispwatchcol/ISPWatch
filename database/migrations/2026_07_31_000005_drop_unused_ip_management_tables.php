<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina las tablas de gestión de rangos IP y la bitácora heredada (hallazgo M-9).
 *
 * Las cuatro cumplen las TRES condiciones para poder retirarlas sin riesgo,
 * verificadas una a una antes de escribir esta migración:
 *
 *   1. COUNT(*) = 0 en producción — con un conteo real, no con la estimación de
 *      pg_stat_user_tables (que para tablas nunca analizadas informa 0 aunque
 *      tengan filas; por confiar en ella se dio por vacío el catálogo cut_type,
 *      que en realidad tenía 3).
 *   2. Sin modelo Eloquent ni referencia alguna en app/, routes/ ni resources/js/.
 *   3. Su función está cubierta por otra cosa: los rangos IP del router viven en
 *      la columna `router.rangos_ip` y las IP asignadas en
 *      `customer_profile.ip_user`; la auditoría la hace `audit_logs`, no
 *      `activity_log`.
 *
 * NO se eliminan `cut_type`, `type_billing` ni `script_version` pese a figurar
 * como "muertas" en un análisis anterior: tienen filas reales (3, 3 y 2),
 * modelo, y endpoints de catálogo que las sirven.
 *
 * El orden importa: `router_ip_range` e `ip_assignment` referencian a `ip_range`.
 */
return new class extends Migration
{
    /** En orden de borrado: primero las que tienen la clave foránea. */
    private const TABLAS = [
        'router_ip_range',
        'ip_assignment',
        'ip_range',
        'activity_log',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            // Salvaguarda: si alguien las empezó a usar entre la auditoría y el
            // despliegue, se deja la tabla en su sitio en lugar de perder datos.
            if (DB::table($tabla)->exists()) {
                continue;
            }

            Schema::drop($tabla);
        }
    }

    public function down(): void
    {
        // Se recrea la estructura vacía para que un rollback deje el esquema
        // consistente. Los datos no se restauran porque no había ninguno.
        if (!Schema::hasTable('ip_range')) {
            Schema::create('ip_range', function (Blueprint $table) {
                $table->id();
                $table->string('range');
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('router_ip_range')) {
            Schema::create('router_ip_range', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('router_id');
                $table->unsignedBigInteger('range_id');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ip_assignment')) {
            Schema::create('ip_assignment', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_range')->nullable();
                $table->string('ip_asig')->default('dynamic');
                $table->string('status')->default('available');
                $table->unsignedBigInteger('router_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('commit')->nullable();
                $table->string('action')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->string('tipo')->nullable();
                $table->timestamps();
            });
        }
    }
};
