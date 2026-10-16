<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se avisó que la llave está por vencer (P-KEYS-1 / KAN-43).
 *
 * El aviso se manda una sola vez por llave. Guardarlo en la fila, en vez de
 * apostar a una ventana fija de un día, hace que un día sin planificador no
 * signifique perder el aviso: la corrida siguiente lo manda igual, y la de
 * después no lo repite. Nullable: los tokens que no son llaves de API nunca se
 * marcan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'expiry_notified_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('expiry_notified_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('personal_access_tokens', 'expiry_notified_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('expiry_notified_at');
        });
    }
};
