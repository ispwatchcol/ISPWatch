<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma remota del contrato: el cliente firma desde un link, sin cuenta.
 *
 * Por qué una tabla y no un signed URL de Laravel (URL::temporarySignedRoute,
 * como el de verify-email): un contrato es un documento legal y lo que le da
 * valor probatorio es el rastro — quién lo abrió, desde qué IP, cuándo, y que
 * el link fuera de un solo uso y revocable. Un signed URL no deja nada de eso:
 * la firma va dentro de la propia URL, no se puede revocar sin rotar
 * APP_KEY (que invalidaría también los correos de verificación) y no hay dónde
 * anotar el intento fallido de verificación.
 *
 * El token NUNCA se guarda en claro: sólo su SHA-256, igual que
 * personal_access_tokens. Quien tenga acceso de lectura a la BD no puede
 * suplantar al cliente y firmar por él.
 *
 * customer_documents.content_sha256 es la huella del PDF ya almacenado. Se
 * calcula DESPUÉS de renderizar (no puede ir impresa dentro del propio PDF sin
 * caer en una referencia circular), y sirve para demostrar años después que el
 * archivo que se exhibe es byte a byte el que se firmó.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_signature_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');

            // SHA-256 en hex del token que viaja en la URL.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');

            // Trazabilidad del envío.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('sent_channel', 20)->nullable();  // email | whatsapp | manual
            $table->string('sent_to', 190)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            // Trazabilidad de la firma.
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 512)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
            // El recordatorio de contratos sin firmar barre por este par.
            $table->index(['signed_at', 'expires_at']);
        });

        Schema::table('customer_documents', function (Blueprint $table) {
            $table->string('content_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_documents', function (Blueprint $table) {
            $table->dropColumn('content_sha256');
        });

        Schema::dropIfExists('contract_signature_links');
    }
};
