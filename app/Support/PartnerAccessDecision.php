<?php

namespace App\Support;

/**
 * Decisión de acceso que ISPWatch le entrega a un AAA externo.
 *
 * POR QUÉ LA DECIDE ISPWATCH Y NO EL INTEGRADOR
 * ----------------------------------------------
 * Hasta el contrato 1.2.x la API entregaba los ingredientes —`service_status`
 * y `is_enabled`— y el contrato le explicaba al integrador cómo combinarlos.
 * Eso deja la regla escrita dos veces, una de cada lado, y cuando difieren
 * manda la del AAA: es la que está frente al cliente final. En la práctica, el
 * sistema que decide quién navega pasa a ser el del integrador.
 *
 * ISPWatch es la autoridad comercial. La regla vive aquí, se aplica igual en
 * `/customers` y en `/services`, y el AAA no la interpreta: aplica `granted`.
 * Si mañana la regla cambia —un estado nuevo, una excepción—, cambia en un
 * solo lugar y el integrador la recibe sin tocar su código.
 *
 * FAIL-CLOSED
 * -----------
 * Sólo se concede acceso con evidencia positiva: estado comercial que da
 * servicio Y ficha habilitada. Un estado desconocido, nulo o nuevo se niega.
 * Es lo que hace segura la frase «el AAA aplica `granted`»: un error de datos
 * de nuestro lado nunca se traduce en servicio regalado.
 */
final class PartnerAccessDecision
{
    /** Estados comerciales que dan servicio. Cualquier otro lo niega. */
    private const GRANTING = [
        'activo' => 'active',
        'gratis' => 'courtesy',
    ];

    /** Estados comerciales que lo niegan, con su motivo publicado. */
    private const DENYING = [
        'suspendido' => 'suspended',
        'cancelado'  => 'cancelled',
        'retirado'   => 'retired',
    ];

    /** Todos los motivos que el contrato publica, en el orden del enum. */
    public const REASONS = [
        'active', 'courtesy', 'suspended', 'cancelled', 'retired', 'disabled', 'unknown_status',
    ];

    /**
     * @return array{granted: bool, reason: string}
     */
    public static function for(?string $serviceStatus, mixed $isEnabled): array
    {
        $status = $serviceStatus !== null ? strtolower(trim($serviceStatus)) : null;

        // El estado comercial manda el motivo cuando niega: «suspendido» le
        // dice más al operador del AAA que «deshabilitado».
        if ($status !== null && isset(self::DENYING[$status])) {
            return ['granted' => false, 'reason' => self::DENYING[$status]];
        }

        if ($status === null || !isset(self::GRANTING[$status])) {
            return ['granted' => false, 'reason' => 'unknown_status'];
        }

        // Estado que da servicio, pero la ficha está deshabilitada: hay datos
        // anteriores a `service_status` donde los dos no coinciden, y en la
        // duda se niega.
        if (!filter_var($isEnabled, FILTER_VALIDATE_BOOLEAN)) {
            return ['granted' => false, 'reason' => 'disabled'];
        }

        return ['granted' => true, 'reason' => self::GRANTING[$status]];
    }
}
