<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `2026_05_27_223002` hizo `customer_documents.customer_id` nullable, pero
     * sólo escribió las ramas de pgsql y mysql: en **sqlite la columna se quedó
     * NOT NULL**. Como la suite corre en sqlite, era imposible escribir una
     * prueba del caso que de verdad ocurre en producción — una foto o acta de
     * instalación que cuelga de un prospecto y por tanto no tiene cliente.
     *
     * Es una divergencia dev/prod, no una diferencia de diseño: se descubrió al
     * probar el borrado en cascada de clientes (2026-08-06), donde esas filas
     * son justo las que no arrastraría ninguna clave foránea.
     *
     * Se limita a sqlite a propósito. En pgsql la columna YA es nullable, y
     * volver a lanzar un `change()` allí arriesgaría la clave foránea con
     * ON DELETE CASCADE que la columna ya tiene, sin ganar nada.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('customer_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('customer_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
