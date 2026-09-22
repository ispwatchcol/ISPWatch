<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Router;
use App\Support\ReconnectionOutcome;

/**
 * ¿Están dadas las condiciones para reconectar a este cliente en su equipo?
 *
 * Se responde ANTES de abrir ninguna sesión contra el RouterBoard, por dos
 * razones. La primera es honestidad: "no hay router asignado" y "el router
 * respondió con error" son problemas distintos, con responsables y acciones
 * distintas, y sólo se pueden distinguir mirando la ficha antes de intentar.
 * La segunda es que intentar sin datos no falla limpio — dispara un SSH a una
 * dirección vacía y vuelve como un timeout genérico que el operador lee como
 * "el router está caído" cuando en realidad falta llenar un campo.
 *
 * Devuelve null cuando NO encuentra impedimentos: vía libre para intentar.
 * Todo lo demás es un código de ReconnectionOutcome.
 *
 * Nota sobre RADIUS: los routers con AAA externo no se tocan nunca desde
 * ISPWatch (el estado del abonado no vive en el equipo), así que para ellos no
 * hay preflight que hacer — RouterProvisioningService ya los resuelve como
 * éxito delegado y aquí se dejan pasar.
 */
class ReconnectionPreflight
{
    /**
     * @return string|null Código de ReconnectionOutcome, o null si se puede intentar.
     */
    public function check(CustomerProfile $profile): ?string
    {
        // 1. El cliente no tiene router en su ficha: no hay equipo al que ir.
        if (!$profile->router_id) {
            return ReconnectionOutcome::PENDIENTE_ROUTER_NO_ASIGNADO;
        }

        // 2. La ficha apunta a un router que no existe en el sistema (se borró,
        //    o el id quedó colgando de una importación). No es lo mismo que no
        //    tener router asignado: aquí el dato está, el equipo no.
        //    Sin scope de tenant a propósito: el aislamiento ya lo garantiza el
        //    llamador, y un router de otra sede debe leerse como "no existe".
        $router = Router::withoutTenantScope()
            ->where('id', $profile->router_id)
            ->when($profile->tenant_id, fn ($q) => $q->where('tenant_id', $profile->tenant_id))
            ->first();

        if (!$router) {
            return ReconnectionOutcome::PENDIENTE_SIN_ROUTER_CONFIGURADO;
        }

        // 3. AAA externo: ISPWatch no escribe en este equipo. Vía libre.
        if ($router->usesRadius()) {
            return null;
        }

        // 4. El equipo está declarado fuera de servicio. Intentar es garantizar
        //    un timeout y un log de error que no dice nada nuevo.
        if (in_array((string) $router->status, ['inactive', 'maintenance'], true)) {
            return ReconnectionOutcome::PENDIENTE_ROUTER_NO_DISPONIBLE;
        }

        if ((bool) $router->falla_general) {
            return ReconnectionOutcome::PENDIENTE_ROUTER_NO_DISPONIBLE;
        }

        // 5. Faltan datos para operar: credenciales del equipo o IP del cliente.
        //    Se mira que existan, NUNCA su contenido, y no se registra cuál de
        //    las dos falta en nada que llegue al navegador.
        if (trim((string) $router->user_rb) === '' || trim((string) $router->password_rb) === '') {
            return ReconnectionOutcome::PENDIENTE_CONFIGURACION_INCOMPLETA;
        }

        if (trim((string) $profile->ip_user) === '') {
            return ReconnectionOutcome::PENDIENTE_CONFIGURACION_INCOMPLETA;
        }

        // 6. Sin dirección a la que discar no hay comunicación posible. Con
        //    WireGuard la dirección del overlay es fija; con L2TP se resuelve
        //    contra /ppp active del CORE y un túnel caído deja esto vacío.
        if ($this->dialAddressFor($router) === '') {
            return ReconnectionOutcome::PENDIENTE_ROUTER_NO_DISPONIBLE;
        }

        return null;
    }

    /**
     * Dirección que el CORE usaría para alcanzar el equipo, o cadena vacía si
     * no hay ninguna. Nunca propaga excepciones: un fallo leyendo el estado del
     * overlay no puede tumbar el registro de un pago, y lo peor que produce es
     * un "router no disponible" de más, que es el lado seguro del error.
     */
    private function dialAddressFor(Router $router): string
    {
        try {
            return trim((string) app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolveIp($router));
        } catch (\Throwable $e) {
            return '';
        }
    }
}
