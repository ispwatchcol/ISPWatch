<?php

namespace App\Support;

/**
 * Desenlace NORMALIZADO de la reconexión automática que dispara un pago.
 *
 * Existe porque "pago registrado" y "servicio reconectado" son dos hechos
 * distintos y el sistema los estaba contando como uno solo. Hasta ahora el
 * resultado viajaba como tres booleanos sueltos (was_suspended / reactivated /
 * router_ok) y un texto libre; con un cliente SIN router asignado, router_ok
 * se quedaba en su valor por defecto `true` y el cajero veía el aviso verde
 * "pago registrado y cliente reactivado" cuando NADIE había tocado ningún
 * equipo. El cliente pagaba y seguía sin servicio.
 *
 * Un código cerrado obliga a nombrar el motivo real: la pantalla, la bitácora
 * y el log de cortes dicen los tres lo mismo, y "no se pudo" deja de ser
 * indistinguible de "sí se pudo".
 *
 * Los mensajes son para el operador de mostrador: dicen qué pasó y qué hacer,
 * sin IPs, usuarios, contraseñas ni el error crudo del MikroTik (eso vive en
 * los logs del servidor, que no se le devuelven al navegador).
 */
final class ReconnectionOutcome
{
    /** El equipo confirmó la reconexión. Único desenlace que puede cantar éxito. */
    public const REACTIVADO_AUTOMATICAMENTE = 'reactivado_automaticamente';

    /** El cliente/servicio no tiene router asignado en su ficha. */
    public const PENDIENTE_ROUTER_NO_ASIGNADO = 'pendiente_router_no_asignado';

    /** La ficha apunta a un router que ya no existe / nunca se configuró. */
    public const PENDIENTE_SIN_ROUTER_CONFIGURADO = 'pendiente_sin_router_configurado';

    /** El router está inactivo, en mantenimiento o con falla general declarada. */
    public const PENDIENTE_ROUTER_NO_DISPONIBLE = 'pendiente_router_no_disponible';

    /** Faltan datos para operar el equipo: credenciales o IP del cliente. */
    public const PENDIENTE_CONFIGURACION_INCOMPLETA = 'pendiente_configuracion_incompleta';

    /** Se intentó y el equipo respondió con error o no respondió. */
    public const PENDIENTE_ERROR_MIKROTIK = 'pendiente_error_mikrotik';

    /** No había nada que reconectar (no estaba cortado, o sigue debiendo). */
    public const NO_APLICA = 'no_aplica';

    /** Ya estaba reconectado antes de este pago. Idempotencia, no un fallo. */
    public const YA_REACTIVADO = 'ya_reactivado';

    /**
     * Desenlaces que dejan al cliente SIN servicio pese al pago. Son los que
     * levantan la alerta persistente y los que hay que poder reintentar.
     */
    public const PENDIENTES = [
        self::PENDIENTE_ROUTER_NO_ASIGNADO,
        self::PENDIENTE_SIN_ROUTER_CONFIGURADO,
        self::PENDIENTE_ROUTER_NO_DISPONIBLE,
        self::PENDIENTE_CONFIGURACION_INCOMPLETA,
        self::PENDIENTE_ERROR_MIKROTIK,
    ];

    /** ¿Este desenlace exige que alguien vaya y haga algo? */
    public static function isPending(?string $outcome): bool
    {
        return in_array((string) $outcome, self::PENDIENTES, true);
    }

    /** Motivo operativo legible. Sin datos internos del equipo. */
    public static function message(?string $outcome): string
    {
        return match ($outcome) {
            self::REACTIVADO_AUTOMATICAMENTE =>
                'El cliente estaba suspendido y el servicio quedó reactivado automáticamente.',

            self::PENDIENTE_ROUTER_NO_ASIGNADO =>
                'El pago quedó registrado, pero la reactivación automática NO se pudo ejecutar: '
                . 'el cliente no tiene un router asignado en su ficha de servicio.',

            self::PENDIENTE_SIN_ROUTER_CONFIGURADO =>
                'El pago quedó registrado, pero la reactivación automática NO se pudo ejecutar: '
                . 'el router asociado al cliente no está configurado en el sistema.',

            self::PENDIENTE_ROUTER_NO_DISPONIBLE =>
                'El pago quedó registrado, pero la reactivación automática quedó PENDIENTE: '
                . 'el router del cliente está fuera de servicio o no está disponible en este momento.',

            self::PENDIENTE_CONFIGURACION_INCOMPLETA =>
                'El pago quedó registrado, pero la reactivación automática NO se pudo ejecutar: '
                . 'la configuración del router o del servicio del cliente está incompleta.',

            self::PENDIENTE_ERROR_MIKROTIK =>
                'El pago quedó registrado, pero la reactivación automática quedó PENDIENTE: '
                . 'no se pudo completar la comunicación con el router del cliente.',

            self::YA_REACTIVADO =>
                'El cliente ya estaba reactivado antes de este pago. No se requirió ninguna acción en el router.',

            default => '',
        };
    }

    /** Qué debe hacer el operador. Vacío cuando no hay nada que hacer. */
    public static function action(?string $outcome): string
    {
        return match ($outcome) {
            self::PENDIENTE_ROUTER_NO_ASIGNADO =>
                'Asigna un router al cliente en su ficha de servicio y luego reintenta la reconexión, '
                . 'o reconéctalo manualmente en el equipo.',

            self::PENDIENTE_SIN_ROUTER_CONFIGURADO =>
                'Configura el router en el módulo de Routers y vuelve a asignarlo al cliente, '
                . 'o reconéctalo manualmente en el equipo.',

            self::PENDIENTE_ROUTER_NO_DISPONIBLE =>
                'Verifica el estado y la conexión del router. Cuando vuelva a estar disponible, '
                . 'reintenta la reconexión desde Acciones masivas → reconexiones pendientes.',

            self::PENDIENTE_CONFIGURACION_INCOMPLETA =>
                'Revisa que el router tenga sus credenciales de acceso cargadas y que el cliente '
                . 'tenga IP asignada; luego reintenta la reconexión.',

            self::PENDIENTE_ERROR_MIKROTIK =>
                'Verifica la conexión con el router y reintenta la reconexión desde '
                . 'Acciones masivas → reconexiones pendientes, o reconecta manualmente al cliente.',

            default => '',
        };
    }

    /** Etiqueta corta para listados y badges. */
    public static function label(?string $outcome): string
    {
        return match ($outcome) {
            self::REACTIVADO_AUTOMATICAMENTE         => 'Reactivado automáticamente',
            self::PENDIENTE_ROUTER_NO_ASIGNADO       => 'Sin router asignado',
            self::PENDIENTE_SIN_ROUTER_CONFIGURADO   => 'Router no configurado',
            self::PENDIENTE_ROUTER_NO_DISPONIBLE     => 'Router no disponible',
            self::PENDIENTE_CONFIGURACION_INCOMPLETA => 'Configuración incompleta',
            self::PENDIENTE_ERROR_MIKROTIK           => 'Error de comunicación',
            self::YA_REACTIVADO                      => 'Ya estaba reactivado',
            self::NO_APLICA                          => 'No aplica',
            default                                  => '',
        };
    }

    /**
     * El bloque que viaja al navegador. Deliberadamente SIN ip, usuario,
     * contraseña ni `error_message` del equipo: esto se pinta en pantalla.
     *
     * @return array{outcome:string,label:string,pending:bool,message:string,action:string}
     */
    public static function describe(?string $outcome): array
    {
        $outcome = (string) ($outcome ?: self::NO_APLICA);

        return [
            'outcome' => $outcome,
            'label'   => self::label($outcome),
            'pending' => self::isPending($outcome),
            'message' => self::message($outcome),
            'action'  => self::action($outcome),
        ];
    }
}
