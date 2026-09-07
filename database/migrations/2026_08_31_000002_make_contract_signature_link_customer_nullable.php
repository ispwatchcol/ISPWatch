<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `contract_signature_links.customer_id` pasa a nullable.
 *
 * POR QUÉ
 *
 * La tabla no tiene clave foránea hacia `users`, y `CustomerDeletionService`
 * tampoco la toca. Al borrar un cliente sus enlaces de firma quedaban con un
 * `customer_id` apuntando a una fila que ya no existe: referencias colgantes
 * que nadie limpia y que ninguna restricción impide.
 *
 * No es un agujero de seguridad —`PublicContractController::customerOf()` ya
 * responde 404 cuando el cliente no aparece, y así está comentado desde antes—
 * pero sí un residuo que ensucia cualquier consulta que cruce las dos tablas.
 *
 * Con la columna nullable, el servicio puede **desvincular** el enlace en vez de
 * dejarlo colgando, igual que ya hace con `prospects.converted_user_id`.
 *
 * POR QUÉ NO SE BORRAN LOS ENLACES
 *
 * Un enlace de firma es un token de acceso efímero, no evidencia: el contrato
 * firmado vive en `customer_documents`. Aun así, borrarlos sería destruir el
 * rastro de que se pidió una firma, y este PR trata precisamente de conservar
 * rastro. Se desvinculan y se revocan; ocupan nada y no sirven para nada.
 *
 * LO QUE NO SE TOCA
 *
 * Ninguna clave foránea financiera (**P-43** sigue abierta), ni el ticket, ni su
 * historial, ni notas ni adjuntos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_signature_links', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });
    }

    /**
     * Revertir sólo es posible si no quedó ningún enlace ya desvinculado: una
     * fila con `customer_id` NULL no puede volver a `NOT NULL`. Se limpian esas
     * filas antes, porque un enlace sin cliente no sirve para nada y su
     * existencia era justamente el residuo que esta migración vino a evitar.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('contract_signature_links')
            ->whereNull('customer_id')
            ->delete();

        Schema::table('contract_signature_links', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
