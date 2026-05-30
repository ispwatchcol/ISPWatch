<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Muestras finas de tráfico WAN por router (retención corta, p.ej. 30 días).
        // Guardamos el delta del intervalo (rx_bytes/tx_bytes) y también el contador
        // crudo acumulado (rx_counter/tx_counter) para calcular el siguiente delta y
        // detectar reinicios del contador.
        if (!Schema::hasTable('traffic_samples')) {
            Schema::create('traffic_samples', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('router_id');
                $table->unsignedBigInteger('rx_bytes')->default(0);   // delta del intervalo (bajada)
                $table->unsignedBigInteger('tx_bytes')->default(0);   // delta del intervalo (subida)
                $table->unsignedBigInteger('rx_counter')->default(0); // contador crudo acumulado al muestrear
                $table->unsignedBigInteger('tx_counter')->default(0);
                $table->timestamp('sampled_at')->index();
                $table->timestamps();

                $table->index(['router_id', 'sampled_at']);
                $table->foreign('router_id')->references('id')->on('router')->cascadeOnDelete();
            });
        }

        // Agregado diario por router (retención indefinida → consumo mensual).
        if (!Schema::hasTable('traffic_daily')) {
            Schema::create('traffic_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('router_id');
                $table->date('day');
                $table->unsignedBigInteger('rx_bytes')->default(0);
                $table->unsignedBigInteger('tx_bytes')->default(0);
                $table->timestamps();

                $table->unique(['router_id', 'day']);
                $table->foreign('router_id')->references('id')->on('router')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_samples');
        Schema::dropIfExists('traffic_daily');
    }
};
