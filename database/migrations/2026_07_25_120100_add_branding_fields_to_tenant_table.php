<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            // Color de marca en hex (ej. #1e5fa8), usado en los documentos generados.
            // Si es null, cada documento cae a su color por defecto actual.
            $table->string('brand_color', 7)->nullable()->after('logo');
            // Texto legal/adicional que se añade al pie de los documentos generados.
            $table->text('document_footer_text')->nullable()->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['brand_color', 'document_footer_text']);
        });
    }
};
