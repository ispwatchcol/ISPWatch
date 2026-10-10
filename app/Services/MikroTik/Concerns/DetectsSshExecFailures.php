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
                . 'host (?:is )?unreachable|network is unreachable|no route to host|action timed out/i',
            $output
        );
    }

    /**
     * True when the CORE opened the SSH session but the client router REJECTED
     * the credentials.
     *
     * Reported by RouterOS as:
     *
     *     failure: authentication failure (/system/ssh-exec; line 1)
     *
     * This is a third case, and it used to fall in the wrong bucket: it is not
     * a connection failure (the TCP session was established, so the regex above
     * does not match it) and it is not a command failure either — NOTHING ran on
     * the client. But the word "failure" matches the generic error vocabulary,
     * so every manager reported it as «no se pudo crear la queue / el secret»,
     * sending the operator to inspect a queue that was never attempted.
     *
     * Kept deliberately tight. RouterOS says «not enough permissions» when a
     * user is logged in but may not run the command — that is a genuine command
     * failure and must NOT land here.
     */
    protected function isSshExecAuthFailure(string $output): bool
    {
        return (bool) preg_match(
            '/authentication failure|permission denied|invalid user|login failed|bad password/i',
            $output
        );
    }

    /**
     * True when the CORE reached the client and the command itself failed.
     *
     * `exit-code` alone is NOT enough: verified against a live CCR2116 (ROS
     * 7.23) through a ROS 7.22 CORE, a command the client's parser rejects
     * still comes back as `exit-code: 0` with the complaint in the output body:
     *
     *     exit-code: 0
     *          output: bad parameter comentario (line 1 column 37)
     *
     * So we check the non-zero exit code AND the RouterOS error vocabulary,
     * including the `(line N column M)` suffix its parser appends. This matters
     * because the managers wrap their `add` in `:do { } on-error={}` — a
     * malformed command is swallowed there and would otherwise read as success.
     */
    protected function isSshExecCommandFailure(string $output): bool
    {
        if (preg_match('/exit-code:\s*[1-9]/i', $output)) {
            return true;
        }

        // The parser always reports the offending token's position; this is the
        // most reliable single signal that the client rejected the command.
        if (preg_match('/\(line \d+ column \d+\)/i', $output)) {
            return true;
        }

        return (bool) preg_match(
            '/\berror\b|\bfailure\b|\bcannot\b|\brefused\b|\bunknown\b|no such item|match any value|'
                . 'expected end of command|syntax error|invalid value|bad command name|bad parameter|'
                . 'ambiguous value|input does not match|no such command|not enough permissions/i',
            $output
        );
    }

    /**
     * Body of the `output:` block ssh-exec wraps the client's stdout in.
     *
     * ROS 7 answers `exit-code: N\n output: <client stdout>`; callers that want
     * to parse what the client actually printed need it without the envelope.
     * Falls back to the raw string when the envelope isn't present (ROS 6
     * builds auto-print bare).
     */
    protected function sshExecOutputBody(string $output): string
    {
        if (preg_match('/output:\s*(.*)\z/is', $output, $m)) {
            return trim($m[1]);
        }

        return trim($output);
    }

    /**
     * Operator-facing explanation for a CORE→client SSH connection failure.
     * Says which hop failed so it stops being misdiagnosed as a plan/profile,
     * credentials or API problem.
     */
    protected function sshExecConnectionFailureMessage(string $clientIp, string $output, ?int $clientSshPort = null): string
    {
        $port = ($clientSshPort && $clientSshPort > 0) ? $clientSshPort : 22;

        // "action timed out" = the TCP connect never completed (nobody answered
        // at all). "<connection failed>"/refused = something answered with a
        // reset. Those point at different fixes, so say which one happened.
        $timedOut = (bool) preg_match('/action timed out|connection timed out/i', $output);

        $cause = $timedOut
            ? 'Nadie respondió en esa dirección (la conexión TCP expiró). Lo más probable es que la IP overlay '
                . 'del router haya cambiado: el secret L2TP del CORE no fija `remote-address`, así que el pool '
                . 'le asigna otra IP al reconectar y la IP guardada en ISPWatch queda obsoleta. '
                . 'Verifica en el CORE con `/ppp active print` cuál es la dirección real de este router.'
            : 'Algo respondió y rechazó la conexión (TCP reset). Casi siempre es el PUERTO: RouterOS marca '
                . 'ssh-exec al 22 por defecto y muchos routers sirven SSH en otro puerto. Revisa `/ip service print` '
                . 'en el router cliente y carga ese puerto en el campo "Puerto SSH" del router en ISPWatch.';

        return 'No se pudo abrir SSH al router ' . $clientIp . ':' . $port . ' desde el CORE. '
            . 'El túnel VPN puede figurar activo, pero eso solo prueba que el router alcanza al CORE; '
            . 'aquí falló la dirección inversa: el CORE no logró abrir SSH hacia el router. '
            . $cause . ' '
            . 'Comprueba también que el servicio SSH esté habilitado (/ip service) y que su `available from` '
            . 'permita la IP overlay del CORE. '
            . 'NO es un problema del plan/perfil, ni de las credenciales, ni de la API. '
            . 'Detalle del router: ' . $output;
    }

    /**
     * Operator-facing explanation for a rejected login on the client router.
     *
     * The ranking of causes is not decorative: the first two are
     * indistinguishable from the panel, and the second one is the trap. The
     * session is opened BY THE CORE, from its overlay address — not by
     * ISPWatch. A RouterOS user restricted with `address=` to the old overlay
     * subnet (or to ISPWatch's own IP) rejects the *correct* password, and the
     * operator swears the credentials are fine because they work from their own
     * laptop.
     */
    protected function sshExecAuthFailureMessage(string $clientIp, string $output, ?int $clientSshPort = null): string
    {
        $port = ($clientSshPort && $clientSshPort > 0) ? $clientSshPort : 22;

        return 'El CORE llegó al router ' . $clientIp . ':' . $port . ' pero el router RECHAZÓ el usuario y la '
            . 'contraseña, así que NO se ejecutó nada: el cambio ni siquiera se intentó. '
            . 'NO es la queue, ni el plan, ni el perfil, ni el túnel — el túnel funcionó, justamente por eso el '
            . 'CORE alcanzó el puerto SSH. Qué revisar, en este orden: '
            . '(1) el usuario y la contraseña de ESE router guardados en ISPWatch (Routers → Editar), por si los '
            . 'cambiaron en el equipo y no aquí. '
            . '(2) Que el usuario de RouterOS no esté limitado por dirección (`/user print detail` en el router): '
            . 'quien marca es el CORE desde su IP overlay, no ISPWatch, así que un `address=` con la IP vieja '
            . 'rechaza la clave correcta — y desde tu equipo esa misma clave sí entra. '
            . '(3) Que esta IP siga siendo la de ESTE router (`/ppp active print` en el CORE): si el pool se la dio '
            . 'a otro equipo al reconectar, te estás autenticando contra el router equivocado. '
            . '(4) Contraseñas con `\`, `$` o comillas: viajan dentro de una línea de comando de RouterOS. '
            . 'NO reintentes en bucle: tras varios fallos RouterOS bloquea la IP de origen —la del CORE— y el '
            . 'síntoma cambiará a «no conecta», que es otro problema distinto. '
            . 'Detalle del router: ' . $output;
    }

    /**
     * Explanation for the case the CORE connected fine but returned nothing.
     *
     * An empty stdout used to fall through every error check and be reported as
     * SUCCESS while nothing had been written on the router — the worst possible
     * outcome, because the operator sees "cliente cargado" and the router never
     * got the secret. Any caller that cannot read the change back must treat
     * empty output as a failure and surface this.
     */
    protected function sshExecEmptyOutputMessage(string $clientIp, ?int $clientSshPort = null): string
    {
        $port = ($clientSshPort && $clientSshPort > 0) ? $clientSshPort : 22;

        return 'El CORE ejecutó el comando contra ' . $clientIp . ':' . $port . ' pero el router no devolvió '
            . 'ninguna salida, así que NO se puede confirmar que el cambio se haya aplicado. '
            . 'Suele deberse a que la sesión SSH anidada superó el tiempo de espera (el CORE tarda más de lo que '
            . 'el servidor está dispuesto a esperar) o a que ssh-exec abortó sin escribir a stdout. '
            . 'Verifica el cambio en el router antes de darlo por bueno y reintenta.';
    }
}
