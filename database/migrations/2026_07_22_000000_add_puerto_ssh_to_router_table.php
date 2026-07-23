<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-router SSH port for the CORE -> client `/system ssh-exec` hop.
 *
 * Every provisioning/suspension/traffic command reaches the client router as
 * `/system ssh-exec address=<ip> ...` executed ON the CORE, and RouterOS
 * defaults that to port 22. Real deployments move SSH off 22 (CORE_TOCAIMA
 * runs it on 2200, winbox on 1996, www on 1991), so the CORE's TCP connect was
 * refused and every push failed with `<connection failed> <ip>:22` — a message
 * that reads like a client firewall problem when it is really us dialling the
 * wrong port.
 *
 * Nullable: null/empty means "use 22", so existing routers keep behaving
 * exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('router', 'puerto_ssh')) {
            Schema::table('router', function (Blueprint $table) {
                $table->integer('puerto_ssh')->nullable()->after('puerto_www');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('router', 'puerto_ssh')) {
            Schema::table('router', function (Blueprint $table) {
                $table->dropColumn('puerto_ssh');
            });
        }
    }
};
