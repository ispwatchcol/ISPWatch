<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('help_categories')) {
            Schema::create('help_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('icon')->nullable();
                $table->text('description')->nullable();
                $table->integer('display_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('help_articles')) {
            Schema::create('help_articles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('help_categories')->onDelete('cascade');
                $table->string('title');
                $table->longText('content');
                $table->text('tips')->nullable();
                $table->boolean('is_published')->default(true);
                $table->integer('display_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_categories');
    }
};
