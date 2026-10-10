<?php

namespace App\Services\MikroTik\Concerns;

/**
 * Single place that assembles the `/system ssh-exec ...` line the CORE runs to
 * reach a client router.
 *
 * Two things were open-coded at ~15 call sites and both bit us:
 *
 *  1. No `port=`. RouterOS defaults ssh-exec to 22, and deployments routinely
 *     move SSH (CORE_TOCAIMA serves it on 2200). The CORE's TCP connect was
 *     refused and the operator got `<connection failed> <ip>:22`, which reads
 *     like the client is blocking us when we were dialling a closed port.
 *
 *  2. The double-escape dance (`addslashes` over a command that already carries
 *     RouterOS quotes) had to be repeated verbatim everywhere — see
 *     routeros-script-escape rules: the inner command must use flat `"` and let
 *     the single addslashes() here add the one escape level the CORE's parser
 *     consumes.
 *
 *  3. Only `"` was escaped in the password. RouterOS also treats `\` as an
 *     escape and `$` as a variable reference inside a quoted string, so a
 *     password carrying either reached the client mangled and came back as
 *     `authentication failure` — reading exactly like a wrong credential.
 */
trait BuildsCoreSshExec
{
    /**
     * @param string   $clientCommand RouterOS command to run ON the client, with flat `"` quotes.
     * @param int|null $clientSshPort Client's SSH port; null/22 keeps the RouterOS default.
     */
    protected function coreSshExecCommand(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $clientCommand,
        ?int $clientSshPort = null
    ): string {
        // Tres caracteres hay que neutralizar, no uno. La contraseña viaja
        // DENTRO de una cadena entrecomillada de RouterOS, y allí `\` escapa,
        // `$` interpola una variable y `"` cierra la cadena. Escapar sólo las
        // comillas dejaba que una clave con `\` o con `$` llegara deformada al
        // router, que respondía `authentication failure` — indistinguible de
        // una credencial equivocada, y por eso costaba tanto de diagnosticar.
        //
        // Un solo `strtr()` y no tres `str_replace()` encadenados: el segundo
        // reemplazo volvería a escapar las barras que introdujo el primero.
        $safePass = strtr($clientPass, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']);

        return '/system ssh-exec address=' . $clientIp
            . $this->sshExecPortArg($clientSshPort)
            . ' user=' . $clientUser
            . ' password="' . $safePass . '"'
            . ' command="' . addslashes($clientCommand) . '"';
    }

    /**
     * ` port=N` when a non-default port is configured, empty otherwise so the
     * emitted command stays byte-identical to the historical one for routers
     * that really do listen on 22.
     */
    protected function sshExecPortArg(?int $clientSshPort): string
    {
        $port = (int) ($clientSshPort ?? 0);

        return ($port > 0 && $port !== 22) ? ' port=' . $port : '';
    }
}
