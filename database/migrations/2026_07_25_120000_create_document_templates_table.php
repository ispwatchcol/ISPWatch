<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            // invoice | contract | installation (App\Models\DocumentTemplate::TYPES)
            $table->string('type', 30);
            $table->longText('body_html');
            // false = "restaurado a la plantilla base" sin perder el borrador guardado.
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Una sola plantilla por tenant y por tipo de documento.
            $table->unique(['tenant_id', 'type']);

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
