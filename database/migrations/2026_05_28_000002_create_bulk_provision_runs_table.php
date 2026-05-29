<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de progreso del aprovisionamiento masivo asíncrono.
     *
     * El aprovisionamiento (SSH al CORE → SSH anidado al router) tarda ~17-34s
     * por cliente, así que no puede correr síncrono bajo el cap de ~60s del
     * gateway. Se dispara un job en cola por cliente y cada uno actualiza este
     * registro; el frontend hace polling de `processed/total`.
     */
    public function up(): void
    {
        Schema::create('bulk_provision_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('status', 20)->default('processing'); // processing | done
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->unsignedInteger('pppoe_skipped_count')->default(0);
            $table->longText('results')->nullable(); // JSON: filas de resultado por cliente
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_provision_runs');
    }
};
