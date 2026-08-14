<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RADIUS como sexto método de control del router.
 *
 * QUÉ SIGNIFICA LA BANDERA
 * ------------------------
 * `radius = true` quiere decir: **el control de este router lo ejecuta un
 * sistema AAA externo, ISPWatch no escribe en el RouterBoard**. El equipo es un
 * NAS que pregunta a un RADIUS en cada conexión, y la configuración por-cliente
 * deja de empujarse por SSH.
 *
 * POR QUÉ ES UNA SOLA COLUMNA Y NO LA CONFIGURACIÓN COMPLETA DEL NAS
 * -------------------------------------------------------------------
 * Una versión anterior de esta migración traía además `radius_secret`,
 * `radius_coa_port`, `radius_nas_identifier` y `radius_walled_garden_list`,
 * pensada para que ISPWatch fuera el cerebro del RADIUS (respondiendo cada
 * Access-Request vía rlm_rest y emitiendo CoA).
 *
 * Ese modelo se archivó en la rama `spike/radius-rlm-rest` por dos razones:
 *
 *   1. Pondría a ISPWatch en el camino crítico de cada autenticación. Para un
 *      SaaS multi-inquilino eso significa que un despliegue deja sin internet a
 *      los abonados de un ISP, no sin panel. Inaceptable como producto.
 *   2. El integrador que motivó el trabajo opera su propio FreeRADIUS y su
 *      propio orquestador. La configuración del NAS es suya, no nuestra:
 *      guardarla aquí sería duplicar una fuente de verdad ajena.
 *
 * Queda entonces lo único que ISPWatch necesita saber de verdad: si debe o no
 * tocar este equipo. Ver `docs/ARQUITECTURA.md` (métodos de control) y
 * `docs/RADIUS_FREERADIUS.md`.
 *
 * POR QUÉ CONVIVE CON LOS CINCO MODOS ANTERIORES
 * -----------------------------------------------
 * El método de control ya era excluyente por router (simple_queue / control_pcq
 * / hotspot / pppoe / dhcp_leases). RADIUS entra en esa lista: los routers que
 * no se migren siguen funcionando igual y la migración es equipo por equipo, a
 * criterio del operador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('router', 'radius')) {
            Schema::table('router', function (Blueprint $table) {
                $table->boolean('radius')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('router', 'radius')) {
            Schema::table('router', function (Blueprint $table) {
                $table->dropColumn('radius');
            });
        }
    }
};
