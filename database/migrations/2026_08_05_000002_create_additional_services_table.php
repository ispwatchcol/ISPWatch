<?php

use App\Models\Billing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de servicios adicionales reutilizables.
 *
 * Hasta ahora "servicio adicional" no era una entidad: la pantalla de Finanzas
 * emitía una factura suelta con ítems escritos a mano, así que "Alquiler de
 * router extra" cobrado a veinte clientes eran veinte textos sin relación entre
 * sí. Esta tabla es la plantilla —nombre y precio— que después se asigna a
 * varios clientes (customer_additional_services) y se cobra dentro de la
 * mensualidad de cada uno, no en factura aparte.
 *
 * Dos columnas merecen explicación porque codifican decisiones de negocio:
 *
 *  - charge_on_courtesy_month: si el servicio se cobra igual durante un mes de
 *    cortesía por instalación. Por defecto SÍ, que es la norma en ISPs: la
 *    promoción que se vendió fue "dos meses de internet gratis", no "dos meses
 *    de equipos gratis", y el equipo cuesta plata todos los meses.
 *
 *  - proration_mode: qué cobrar el mes en que se activa el servicio, con el
 *    MISMO vocabulario que la política de primera factura de los planes
 *    (none / prorated / full), para que el operador no aprenda dos idiomas.
 *    Por defecto 'full' —a diferencia de los planes, que son 'none'— porque un
 *    adicional suele ser algo físico ya entregado y es el único modo cuyo monto
 *    se puede predecir sin hacer cuentas. Quien quiera empezar el mes siguiente
 *    ya tiene starts_at.
 *
 * price va en decimal(15,2), como invoices e invoice_items y no como expenses
 * (10,2): estos montos terminan siendo ítems de factura y deben caber igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('charge_on_courtesy_month')->default(true);
            $table->string('proration_mode', 20)->default(Billing::FIRST_INVOICE_FULL);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            // El listado del catálogo pide siempre los de UN tenant, casi
            // siempre sólo los activos (el selector de asignación).
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_services');
    }
};
