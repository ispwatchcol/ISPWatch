<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tabla `billing` (configuración de facturación por router) tiene tenant_id
 * desde 2026_01_20_232000, pero RouterController sólo empezó a poblarlo
 * después: las filas anteriores a ese cambio quedaron en NULL.
 *
 * El acceso hoy no depende de esa columna — a un `billing` sólo se llega por
 * `router.billing_router_id`, y Router sí lleva el global scope de tenant, así
 * que la frontera está puesta un nivel más arriba y no hay fuga. Lo que falta
 * es que la fila SEPA de quién es, que es lo único que puede leer una política
 * de Row Level Security.
 *
 * Ojo al sentido de la relación: la llave la tiene el router
 * (router.billing_router_id → billing.id), no al revés. Una fila de billing a
 * la que ningún router apunte se queda en NULL a propósito: es configuración
 * huérfana que ya no factura nada, y adivinarle un dueño sería inventar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Subconsulta correlacionada: mismo motivo que en la migración de
        // customer_profile — es lo que entienden igual PostgreSQL y SQLite.
        DB::table('billing')
            ->whereNull('tenant_id')
            ->update([
                'tenant_id' => DB::raw(
                    '(select router.tenant_id from router where router.billing_router_id = billing.id)'
                ),
            ]);
    }

    public function down(): void
    {
        // No reversible: no se puede distinguir el NULL original del que
        // dejaría revertir, y volver a vaciarlos no arregla nada.
    }
};
