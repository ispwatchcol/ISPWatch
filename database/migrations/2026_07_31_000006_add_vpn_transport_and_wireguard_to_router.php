<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transporte VPN por router: WireGuard o L2TP/IPsec.
 *
 * POR QUÉ NO ES UNA MIGRACIÓN "A WIREGUARD" SINO UN CAMPO POR ROUTER
 * ------------------------------------------------------------------
 * WireGuard existe desde RouterOS 7.1. En v6 NO hay, y no va a haberlo.
 * Así que los dos transportes conviven de forma permanente y la elección es
 * por equipo, nunca global:
 *
 *   v7  → WireGuard. Un solo flujo UDP y el peer se identifica por clave
 *         pública, así que el CORE aprende el endpoint venga de donde venga.
 *   v6  → L2TP/IPsec, con las reglas anti-NAT/anti-marcado obligatorias en el
 *         script (para esos equipos son la única defensa disponible).
 *
 * EL FALLO QUE ORIGINA ESTO (2026-07-30, CORE_TOCAIMA, 8 días caído)
 * ------------------------------------------------------------------
 * El router mandaba el IKE por una IP pública y el L2TP por otra — las dos
 * suyas, por multi-WAN con tabla de ruteo aparte. El CORE levantaba la SA
 * contra la primera; el paquete L2TP llegaba de la segunda sin política que lo
 * cubriera y, con use-ipsec=required, lo rechazaba. Con WireGuard esa clase de
 * falla no existe: no hay canal de control separado del de datos.
 *
 * NOTA SOBRE wg_private_key
 * -------------------------
 * Va cifrada por cast en el modelo. wg_public_key NO se cifra a propósito: se
 * compara contra lo que el CORE tiene registrado, y un valor cifrado no es
 * consultable en SQL (ver la advertencia en App\Models\Router::$casts).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('router', 'vpn_transport')) {
            Schema::table('router', function (Blueprint $table) {
                $table->string('vpn_transport', 16)->default('l2tp');
            });
        }

        if (!Schema::hasColumn('router', 'wg_private_key')) {
            Schema::table('router', function (Blueprint $table) {
                $table->text('wg_private_key')->nullable();
            });
        }

        if (!Schema::hasColumn('router', 'wg_public_key')) {
            Schema::table('router', function (Blueprint $table) {
                $table->string('wg_public_key', 64)->nullable();
            });
        }

        if (!Schema::hasColumn('router', 'wg_address')) {
            Schema::table('router', function (Blueprint $table) {
                $table->string('wg_address', 45)->nullable();
            });
        }

        if (!Schema::hasColumn('router', 'wg_listen_port')) {
            Schema::table('router', function (Blueprint $table) {
                $table->unsignedInteger('wg_listen_port')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['vpn_transport', 'wg_private_key', 'wg_public_key', 'wg_address', 'wg_listen_port'] as $column) {
            if (Schema::hasColumn('router', $column)) {
                Schema::table('router', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
