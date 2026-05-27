<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prospects')) return;

        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 120);
            $table->string('last_name', 120)->nullable();
            $table->string('cedula', 40)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('tel', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->text('notes')->nullable();
            // interesado | agendado | instalado | convertido | rechazado
            $table->string('status', 20)->default('interesado');
            $table->unsignedBigInteger('converted_user_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('converted_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
