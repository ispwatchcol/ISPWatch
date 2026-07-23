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
        $safePass = str_replace('"', '\\"', $clientPass);

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
