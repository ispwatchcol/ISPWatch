<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * ¿Está bien configurado el tenant operador de las llaves de API? (P-35 / KAN-38)
 *
 * `config('api_keys.operator_tenant_id')` decide quién puede usar la emisión
 * centralizada (ApiClientController, la pestaña «Llaves API» del operador). Si
 * apunta a un tenant que no existe —en producción el valor por defecto `1` no
 * existe—, esa función no falla: desaparece. Nadie ve un error, la pestaña no
 * se dibuja y en su lugar sale la de auto-servicio. Un id inexistente y uno
 * válido se veían exactamente igual.
 *
 * Esto no corrige la configuración, que es una variable de entorno. Sólo
 * consigue que el problema se vea, y sólo para quien puede arreglarlo.
 */
final class ApiKeyOperator
{
    /**
     * Motivo legible si el tenant operador está mal configurado, o null si está bien.
     */
    public static function configurationIssue(): ?string
    {
        $id = (int) config('api_keys.operator_tenant_id');

        if ($id <= 0) {
            return 'No hay tenant operador de llaves de API configurado '
                . '(API_KEYS_OPERATOR_TENANT_ID). La emisión centralizada de llaves no está disponible para nadie.';
        }

        if (!Tenant::withoutGlobalScopes()->whereKey($id)->exists()) {
            return "El tenant operador de llaves de API configurado (API_KEYS_OPERATOR_TENANT_ID = {$id}) no existe. "
                . 'La emisión centralizada de llaves no está disponible para nadie hasta que apunte a un tenant real.';
        }

        return null;
    }
}
