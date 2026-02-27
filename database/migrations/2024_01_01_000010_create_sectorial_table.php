<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sectorial', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_rb')->nullable();
            $table->string('pass_rb')->nullable();
            $table->integer('zona_id')->nullable();
            $table->string('ssid')->nullable();
            $table->string('frequency')->nullable();
            $table->string('node_tower')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });

        // Add geography column for coordinates (PostGIS)
        // Wrapped in try/catch: silently skips on SQLite (testing) where geography is unsupported
        try {
            DB::statement('ALTER TABLE sectorial ADD COLUMN coordinates geography(Point, 4326)');
        } catch (\Exception $e) {
            // SQLite fallback: add a plain text column to store coordinates
            if (!Schema::hasColumn('sectorial', 'coordinates')) {
                Schema::table('sectorial', function ($table) {
                    $table->text('coordinates')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sectorial');
    }
};
