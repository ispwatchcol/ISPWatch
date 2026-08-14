<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RADIUS como sexto método de control del router.
 *
 * POR QUÉ ES UN BOOLEANO MÁS Y NO UNA MIGRACIÓN GLOBAL
 * -----------------------------------------------------
 * El método de control ya es excluyente por router (simple_queue / control_pcq /
 * hotspot / pppoe / dhcp_leases). RADIUS entra en esa misma lista: los routers
 * que no se migren siguen funcionando exactamente igual, y la migración de la
 * flota es router por router, a criterio del operador. No hay fecha límite
 * forzada ni un big bang que haya que coordinar con el cliente.
 *
 * QUÉ CAMBIA DE FONDO CUANDO UN ROUTER TIENE radius = true
 * --------------------------------------------------------
 * Se invierte el flujo. Hoy ISPWatch EMPUJA por SSH (queue, secret PPPoE,
 * lease). Con RADIUS el router PREGUNTA en cada conexión y ISPWatch responde,
 * así que el aprovisionamiento por-cliente deja de escribir en el RouterBoard.
 * Ese es, de paso, el origen de los 504 del gateway en las cargas masivas.
 *
 * SOBRE radius_secret
 * -------------------
 * Va cifrado por cast en el modelo, igual que password_rb. Ojo con la
 * advertencia de App\Models\Router::$casts: un valor cifrado NO es consultable
 * en SQL, así que nada puede filtrar por esta columna. El generador de
 * clients.conf lee los routers y descifra POR MODELO, nunca con SQL crudo.
 *
 * NO HAY TABLA radius_nas_clients APARTE
 * --------------------------------------
 * El diseño original la contemplaba, pero habría duplicado exactamente estas
 * columnas con un join de por medio. El clients.conf se genera desde
 * `router where radius = true`, que es la única fuente de verdad.
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

        if (!Schema::hasColumn('router', 'radius_secret')) {
            Schema::table('router', function (Blueprint $table) {
                // text, no string: el cifrado del cast infla el valor muy por
                // encima del secreto original.
                $table->text('radius_secret')->nullable();
            });
        }

        if (!Schema::hasColumn('router', 'radius_coa_port')) {
            Schema::table('router', function (Blueprint $table) {
                // 3799 es el puerto estándar de CoA/Disconnect (RFC 5176).
                // RouterOS lo expone con `/radius incoming set accept=yes`.
                $table->unsignedInteger('radius_coa_port')->default(3799);
            });
        }

        if (!Schema::hasColumn('router', 'radius_nas_identifier')) {
            Schema::table('router', function (Blueprint $table) {
                // Identifica al NAS cuando su IP del overlay rota: el CORE
                // reasigna direcciones del pool en cada reconexión, así que la
                // IP no sirve como identidad estable (ver RouterEndpointResolver).
                $table->string('radius_nas_identifier', 64)->nullable();
            });
        }

        if (!Schema::hasColumn('router', 'radius_walled_garden_list')) {
            Schema::table('router', function (Blueprint $table) {
                // Address-list que agrupa a los cortados por mora. El perfil
                // restringido que devuelve /authorize mete al cliente aquí y el
                // firewall del router solo le deja ver el portal de pago.
                $table->string('radius_walled_garden_list', 64)->nullable()->default('morosos');
            });
        }
    }

    public function down(): void
    {
        $columns = [
            'radius',
            'radius_secret',
            'radius_coa_port',
            'radius_nas_identifier',
            'radius_walled_garden_list',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('router', $column)) {
                Schema::table('router', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
