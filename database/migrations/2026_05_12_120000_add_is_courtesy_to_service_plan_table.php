<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_plan', function (Blueprint $table) {
            $table->boolean('is_courtesy')->default(false)->after('cost_product');
        });
    }

    public function down(): void
    {
        Schema::table('service_plan', function (Blueprint $table) {
            $table->dropColumn('is_courtesy');
        });
    }
};
