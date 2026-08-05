<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tipos de factura.
 *
 * Hasta ahora los cuatro tipos vivían como constantes en App\Models\Invoice, así
 * que un operador no podía facturar "Equipos" o "TV" sin un despliegue. La tabla
 * sigue el mismo patrón que los roles globales: las filas con tenant_id NULL son
 * del sistema (las comparten todos los tenants y son de solo lectura) y cada
 * tenant agrega las suyas encima.
 *
 * invoices.invoice_type sigue siendo el slug en texto: no hace falta FK ni
 * reescribir facturas viejas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_types', function (Blueprint $table) {
            $table->id();
            // NULL = tipo del sistema, compartido por todos los tenants.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('slug', 50);
            $table->string('name', 100);
            // Clave de paleta (blue, emerald, purple...), NO clases de Tailwind:
            // el front las mapea a clases completas para que el purge no se las coma.
            $table->string('color', 30)->default('slate');
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
        });

        $now = now();

        DB::table('invoice_types')->insert(array_map(
            fn ($row) => $row + [
                'tenant_id'   => null,
                'is_system'   => true,
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                ['slug' => 'monthly',        'name' => 'Plan Mensual',       'color' => 'blue',    'sort_order' => 1, 'description' => 'Servicio mensual generado por la facturación automática.'],
                ['slug' => 'installation',   'name' => 'Instalación',        'color' => 'emerald', 'sort_order' => 2, 'description' => 'Cobro de la instalación del servicio.'],
                ['slug' => 'additional',     'name' => 'Servicio Adicional', 'color' => 'purple',  'sort_order' => 3, 'description' => 'Cargo puntual fuera del ciclo regular.'],
                ['slug' => 'service_charge', 'name' => 'Cargo de Ticket',    'color' => 'amber',   'sort_order' => 4, 'description' => 'Cargo originado en un ticket de soporte.'],
            ]
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_types');
    }
};
