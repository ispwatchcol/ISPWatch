<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\BuildsCoreSshExec;
use App\Services\MikroTik\Concerns\DetectsSshExecFailures;
use App\Services\MikroTik\Concerns\NormalizesRouterComment;
use Illuminate\Support\Facades\Log;

/**
 * IP/MAC Binding Manager
 *
 * Two independent, ADDITIVE per-client options that can be combined with any
 * control mode (queue/pppoe/hotspot/pcq/dhcp):
 *
 *  - IP Bindings ("Forzar IP a MAC"): a static `/ip arp` entry pinning the
 *    client IP to its MAC on the LAN interface. Does not block anything by
 *    itself; the interface is NOT switched to arp=reply-only.
 *
 *  - Amarre IP/MAC ("Bloqueo por pares IP-MAC"): a top-of-chain
 *    `/ip firewall filter` rule that drops forward traffic coming from the
 *    client IP when the source MAC does not match (anti-spoofing). Surgical:
 *    one rule per client, other clients are untouched.
 *
 * Both go through the proven CORE ssh-exec path with HARDENED result detection
 * (a non-zero ssh-exec exit code or a RouterOS parser error is NOT success —
 * see the HotspotManager `comment=` silent-failure bug). Same syntax on
 * RouterOS v6/v7.
 */
class IpMacBindingManager
{
    use NormalizesRouterComment;
    use DetectsSshExecFailures;
    use BuildsCoreSshExec;

    private MikroTikConnectionManager $connectionManager;
    private MikroTikApiProtocol $apiProtocol;

    public function __construct(
        ?MikroTikConnectionManager $connectionManager = null,
        ?MikroTikApiProtocol $apiProtocol = null
    ) {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
        $this->apiProtocol = $apiProtocol ?? $this->connectionManager->getApiProtocol();
    }

    // ==================== IP BINDINGS: STATIC ARP ====================

    /**
     * Pin the client IP to its MAC with a static /ip arp entry on the LAN
     * interface. Idempotent: any prior entry for the address is removed first.
     */
    public function ensureArpBinding(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $mac,
        ?string $lanInterface,
        int $clientPort = 8728,
        ?string $comment = null,
        ?int $clientSshPort = null
    ): array {
        // SECURITY (OWASP A03): address/mac/interface are interpolated; reject
        // anything that is not a valid IP / MAC, and require a LAN interface.
        if (filter_var(trim($targetIp), FILTER_VALIDATE_IP) === false) {
            return ['success' => false, 'message' => 'IP del cliente inválida para el ARP estático.'];
        }
        $mac = strtoupper(trim($mac));
        if (!preg_match('/^([0-9A-F]{2}[:-]){5}[0-9A-F]{2}$/', $mac)) {
            return ['success' => false, 'message' => 'MAC del cliente inválida (formato AA:BB:CC:DD:EE:FF).'];
        }
        $mac      = str_replace('-', ':', $mac);
        $targetIp = trim($targetIp);
        $lan      = trim((string) $lanInterface);
        if ($lan === '') {
            return ['success' => false, 'message' => 'El router no tiene interfaz LAN configurada (necesaria para el ARP estático).'];
        }
        $comment = $this->normalizeRouterComment($comment);

        Log::info('[IpMacBindingManager] Ensuring static ARP', [
            'client_ip' => $clientIp, 'target' => $targetIp, 'mac' => $mac, 'lan' => $lan,
        ]);

        return $this->runViaCore(
            $clientIp, $clientUser, $clientPass,
            $this->buildArpCommand($targetIp, $mac, $lan, $comment),
            'ARP estático (IP Bindings)',
            $clientSshPort
        );
    }

    private function buildArpCommand(string $targetIp, string $mac, string $lan, string $comment): string
    {
        $lanV = $this->escapeRouterOsQuotedValue($lan);
        $comm = $this->escapeRouterOsQuotedValue($comment);

        // Remove any prior entry for this address (dynamic or stale static),
        // then add the static pin so the MAC/interface is always current and we
        // never duplicate.
        $remove = '/ip arp remove [find address=' . $targetIp . ']';
        $add    = '/ip arp add address=' . $targetIp . ' mac-address=' . $mac
            . ' interface="' . $lanV . '" comment="' . $comm . '"';

        return ':do { ' . $remove . ' } on-error={}; ' . $add;
    }

    // ==================== AMARRE: FIREWALL DROP RULE ====================

    /**
     * Drop forward traffic from the client IP whose source MAC does not match
     * (anti-spoofing). Idempotent and placed at the top of the forward chain so
     * it is not shadowed by an earlier accept rule.
     */
    public function ensureMacAmarre(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $mac,
        int $clientPort = 8728,
        ?string $label = null,
        ?int $clientSshPort = null
    ): array {
        if (filter_var(trim($targetIp), FILTER_VALIDATE_IP) === false) {
            return ['success' => false, 'message' => 'IP del cliente inválida para el amarre IP/MAC.'];
        }
        $mac = strtoupper(trim($mac));
        if (!preg_match('/^([0-9A-F]{2}[:-]){5}[0-9A-F]{2}$/', $mac)) {
            return ['success' => false, 'message' => 'MAC del cliente inválida (formato AA:BB:CC:DD:EE:FF).'];
        }
        $mac      = str_replace('-', ':', $mac);
        $targetIp = trim($targetIp);

        Log::info('[IpMacBindingManager] Ensuring IP/MAC amarre rule', [
            'client_ip' => $clientIp, 'target' => $targetIp, 'mac' => $mac,
        ]);

        return $this->runViaCore(
            $clientIp, $clientUser, $clientPass,
            $this->buildAmarreCommand($targetIp, $mac, $label),
            'amarre IP/MAC',
            $clientSshPort
        );
    }

    private function buildAmarreCommand(string $targetIp, string $mac, ?string $label): string
    {
        // Stable key by IP for the find (firewall comments are NOT unique, so an
        // add-on-error would duplicate the rule). The label (client name) is
        // appended to the new rule's comment only for readability; the find uses
        // the IP-keyed prefix so a renamed client still replaces its old rule.
        $key     = 'ISPWatch-amarre-' . $targetIp;
        $human   = ($label !== null && trim($label) !== '') ? ' ' . $this->asciiRouterComment(trim($label)) : '';
        $comment = $this->escapeRouterOsQuotedValue($key . $human);
        $keyEsc  = $this->escapeRouterOsQuotedValue($key);
        $findKey = '[find comment~"' . $keyEsc . '"]';
        $rule    = 'chain=forward src-address=' . $targetIp . ' src-mac-address=!' . $mac . ' action=drop';

        // Remove any prior rule(s) for this client, add one fresh, then move it
        // to the top of the forward chain so it isn't shadowed by an accept rule.
        return ':do { /ip firewall filter remove ' . $findKey . ' } on-error={}; '
            . '/ip firewall filter add ' . $rule . ' comment="' . $comment . '"; '
            . ':do { /ip firewall filter move ' . $findKey . ' 0 } on-error={}';
    }

    // ==================== SHARED CORE SSH RUNNER ====================

    private function runViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $clientCommand,
        string $what,
        ?int $clientSshPort = null
    ): array {
        try {
            $coreCommand = $this->coreSshExecCommand($clientIp, $clientUser, $clientPass, $clientCommand, $clientSshPort);

            Log::info('[IpMacBindingManager] CORE SSH direct: ssh-exec', ['client_ip' => $clientIp, 'client_port' => $clientSshPort ?? 22, 'what' => $what]);

            $result = $this->connectionManager->executeSsh($coreCommand);

            if (!$result['success']) {
                return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => $result['message'] ?? 'No se pudo conectar al CORE via SSH'];
            }

            $output = trim((string) ($result['output'] ?? ''));

            // CORE→client SSH connection failure — nothing ran on the client.
            if ($output && $this->isSshExecConnectionFailure($output)) {
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => $this->sshExecConnectionFailureMessage($clientIp, $output, $clientSshPort),
                ];
            }

            // Empty stdout is not success: ssh-exec that times out or aborts
            // writes nothing, and calling that "sincronizado" leaves the router
            // untouched while the UI reports it worked.
            if ($output === '') {
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => $this->sshExecEmptyOutputMessage($clientIp, $clientSshPort),
                ];
            }

            if ($this->isSshExecCommandFailure($output)) {
                return [
                    'success'    => false,
                    'method'     => 'CORE_SSH_DIRECT',
                    'definitive' => true,
                    'message'    => "No se pudo sincronizar {$what}. Detalle del router: " . $output,
                ];
            }

            Log::info('[IpMacBindingManager] CORE SSH direct: success', ['what' => $what]);

            return ['success' => true, 'method' => 'CORE_SSH_DIRECT', 'action' => 'upserted', 'message' => "{$what} sincronizado via CORE"];
        } catch (\Throwable $e) {
            Log::error('[IpMacBindingManager] CORE SSH exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function escapeRouterOsQuotedValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return addcslashes($value, "\\\"\$");
    }
}
