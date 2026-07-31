<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // false = shell fijo + placeholders (comportamiento actual, sin
            // cambios). true = documento HTML completo del tenant, saneado
            // por App\Services\Templates\AdvancedTemplateSanitizer y
            // renderizado sin shell (Pdf::loadHTML directo). Las plantillas
            // existentes quedan en false, sin tocarlas.
            $table->boolean('is_advanced_mode')->default(false)->after('body_html');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('is_advanced_mode');
        });
    }
};
