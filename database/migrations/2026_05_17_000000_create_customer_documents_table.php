<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            // cedula | instalacion | contrato | otros
            $table->string('type', 30)->default('otros');
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->integer('file_size')->default(0);
            $table->string('mime_type', 100)->nullable();
            // true only for the system-generated, on-screen-signed contract PDF
            $table->boolean('signed')->default(false);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'type']);
            $table->index('tenant_id');
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
    }
};
