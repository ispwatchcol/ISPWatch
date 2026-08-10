<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadatos de las llaves de la API pública sobre la tabla de Sanctum.
 *
 * Se extiende `personal_access_tokens` en vez de crear una tabla paralela para
 * que el hash del token siga siendo el de Sanctum (findToken + last_used_at ya
 * resueltos y probados). Las columnas son todas nullable: los tokens que no son
 * llaves de API — hoy ninguno, pero el SPA podría emitirlos mañana — no se ven
 * afectados.
 *
 * `allowed_ips` es obligatoria a nivel de aplicación (EnsureApiKeyRequest
 * rechaza la llave si viene vacía), no a nivel de esquema, porque la columna
 * también existe para tokens que no pasan por ese middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Lista blanca de IPs/CIDR desde las que la llave puede usarse.
            $table->json('allowed_ips')->nullable()->after('abilities');

            // Revocación explícita. Se conserva la fila (en vez de borrarla)
            // para que el log de auditoría siga pudiendo nombrar la llave.
            $table->timestamp('revoked_at')->nullable()->after('last_used_at');

            // Última IP que usó la llave: permite detectar un uso desde un
            // origen inesperado aunque esté dentro de la allowlist.
            $table->string('last_used_ip', 45)->nullable()->after('revoked_at');

            // Quién emitió la llave (usuario del tenant operador).
            $table->unsignedBigInteger('created_by')->nullable()->after('last_used_ip');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['allowed_ips', 'revoked_at', 'last_used_ip', 'created_by']);
        });
    }
};
