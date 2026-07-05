<?php

namespace App\Services\MikroTik\Concerns;

/**
 * Distinguishes a CORE→client SSH *connection* failure from a client-side
 * *command* rejection in the stdout of `/system ssh-exec`.
 *
 * When the CORE cannot open the SSH session to the client router it prints a
 * line like:
 *
 *     failure: closing connection: <connection failed> 172.16.16.252:22 (140)
 *
 * That word "failure" also matches the generic error regex the managers use to
 * detect a *command* failure, so without this guard a plain connectivity
 * problem gets misreported as "the plan/profile isn't loaded on the router" or
 * "the command failed" — sending the operator down the wrong path. In reality
 * NOTHING ran on the client: the SSH never connected (SSH service off, wrong
 * port, or the client firewall / `allowed-from` is dropping the CORE's mgmt IP).
 */
trait DetectsSshExecFailures
{
    /**
     * True when ssh-exec output indicates the CORE could not even establish the
     * SSH session to the client router (TCP/handshake failure), as opposed to
     * the client accepting the connection and rejecting the command.
     */
    protected function isSshExecConnectionFailure(string $output): bool
    {
        return (bool) preg_match(
            '/<connection failed>|closing connection|connection timed out|connection refused|'
                . 'host (?:is )?unreachable|network is unreachable|no route to host/i',
            $output
        );
    }

    /**
     * Operator-facing explanation for a CORE→client SSH connection failure.
     * Says which hop failed so it stops being misdiagnosed as a plan/profile,
     * credentials or API problem.
     */
    protected function sshExecConnectionFailureMessage(string $clientIp, string $output): string
    {
        return 'No se pudo conectar por SSH al router ' . $clientIp . ' desde el CORE. '
            . 'El túnel VPN puede figurar activo, pero eso solo prueba que el router alcanza al CORE; '
            . 'aquí falló la dirección inversa: el CORE no logró abrir SSH (puerto 22) hacia el router. '
            . 'Revisa EN EL ROUTER CLIENTE: que el servicio SSH esté habilitado (/ip service), en el puerto 22, '
            . 'y que el firewall / `allowed-from` permita la IP de gestión del CORE. '
            . 'NO es un problema del plan/perfil, ni de las credenciales, ni de la API. '
            . 'Detalle del router: ' . $output;
    }
}
