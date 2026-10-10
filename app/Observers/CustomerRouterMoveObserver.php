<?php

namespace App\Observers;

use App\Jobs\PurgeCustomerFromPreviousRouterJob;
use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\User;

/**
 * Al mudar a un cliente de router, encola la limpieza del router anterior
 * (KAN-119).
 *
 * Observer y no código en el controlador por la misma razón que los demás: el
 * router cambia por el panel y por la carga masiva de actualización, y las dos
 * puertas dejaban el mismo residuo.
 *
 * La identidad se toma de los valores ORIGINALES: es la que el cliente tenía en
 * el router viejo, aunque en la misma edición también le hayan cambiado la IP o
 * el usuario PPPoE. Dentro de `updated` el modelo todavía la conserva.
 *
 * Se despacha `afterCommit`: si la edición hace rollback, el cliente sigue en
 * el router viejo y no hay nada que limpiar.
 */
class CustomerRouterMoveObserver
{
    public function updated(CustomerProfile $profile): void
    {
        if (!$profile->wasChanged('router_id')) {
            return;
        }

        $previousId = (int) $profile->getOriginal('router_id');

        if (!$previousId) {
            return;
        }

        $previous = Router::withoutGlobalScope('tenant')->find($previousId);

        // RADIUS: ISPWatch no escribió nada en ese equipo. Sin credenciales: no
        // hay forma de entrar, y CustomerDeletionService ya avisa ese caso.
        if (!$previous || $previous->usesRadius() || !$previous->user_rb || !$previous->password_rb) {
            return;
        }

        $tenantId = (int) User::withoutGlobalScopes()->whereKey($profile->user_id)->value('tenant_id');

        if (!$tenantId) {
            return;
        }

        PurgeCustomerFromPreviousRouterJob::dispatch(
            $previousId,
            (int) $profile->user_id,
            $tenantId,
            [
                'ip'               => $profile->getOriginal('ip_user'),
                'pppoe_username'   => $profile->getOriginal('pppoe_username'),
                'hotspot_username' => $profile->getOriginal('hotspot_username'),
                'mac_address'      => $profile->getOriginal('mac_address'),
            ],
        )->afterCommit();
    }
}
