<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sectorial', function (Blueprint $table) {
            if (!Schema::hasColumn('sectorial', 'element_type')) {
                $table->string('element_type', 20)->default('sectorial')->after('name');
            }
        });

        Schema::create('sectorial_photo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sectorial_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->integer('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('caption')->nullable();
            $table->timestamps();

            $table->foreign('sectorial_id')->references('id')->on('sectorial')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->index(['sectorial_id', 'created_at']);
        });

        Schema::create('sectorial_note', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sectorial_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('title')->nullable();
            $table->text('content');
            $table->timestamps();

            $table->foreign('sectorial_id')->references('id')->on('sectorial')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->index(['sectorial_id', 'created_at']);
        });

        Schema::create('sectorial_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sectorial_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('action', 50);
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('sectorial_id')->references('id')->on('sectorial')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->index(['sectorial_id', 'created_at']);
        });

        Schema::table('support_ticket', function (Blueprint $table) {
            if (!Schema::hasColumn('support_ticket', 'sectorial_id')) {
                $table->unsignedBigInteger('sectorial_id')->nullable()->after('staff_id');
                $table->foreign('sectorial_id')->references('id')->on('sectorial')->onDelete('set null');
                $table->index('sectorial_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            if (Schema::hasColumn('support_ticket', 'sectorial_id')) {
                $table->dropForeign(['sectorial_id']);
                $table->dropColumn('sectorial_id');
            }
        });

        Schema::dropIfExists('sectorial_history');
        Schema::dropIfExists('sectorial_note');
        Schema::dropIfExists('sectorial_photo');

        Schema::table('sectorial', function (Blueprint $table) {
            if (Schema::hasColumn('sectorial', 'element_type')) {
                $table->dropColumn('element_type');
            }
        });
    }
};
