<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tamaño y orientación del papel por plantilla (auditoría 2026-08-05).
     *
     * Hasta ahora TemplateRenderer entregaba todo a Pdf::loadHTML()/loadView()
     * sin setPaper(), o sea siempre el default de config/dompdf.php (a4
     * vertical). Un contrato CRC a dos columnas — el formato estándar en
     * Colombia, y el que exportan sistemas como WispHub — necesita ~950px de
     * ancho; A4 vertical solo da ~698px útiles a 96dpi, así que dompdf lo
     * aprieta y descuadra la maquetación completa. A4 horizontal da ~1027px y
     * cabe sin tocar el diseño.
     *
     * Columnas de texto (no enum) a propósito: agregar un tamaño de papel
     * nuevo no debe requerir una migración de tipo, y un enum de Postgres no
     * existe en el sqlite donde corre la suite de tests. La validación real
     * vive en App\Http\Requests\UpdateDocumentTemplateRequest contra
     * App\Models\DocumentTemplate::PAGE_SIZES / PAGE_ORIENTATIONS.
     *
     * Los defaults reproducen exactamente el comportamiento actual, así que
     * las plantillas ya guardadas siguen saliendo idénticas.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('page_size', 10)->default('a4')->after('is_advanced_mode');
            $table->string('page_orientation', 10)->default('portrait')->after('page_size');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['page_size', 'page_orientation']);
        });
    }
};
