<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MikroTik\MikroTikConnectionManager;
use App\Services\MikroTik\SshTunnelManager;
use App\Services\MikroTikSshService;
use Illuminate\Console\Command;

/**
 * End-to-end diagnostic for the "Configurar Interfaz WAN" flow.
 *
 *   php artisan router:diagnose-wan <router_id>
 *
 * Runs every step the UI runs and prints the raw output of each one so you can
 * see exactly where it fails: SSH to CORE, tunnel open, TCP probe, direct API
 * login, and each of the three SSH-via-CORE command variants (with the raw
 * RouterOS response for each variant). Use this when the modal shows a generic
 * "Puerto X no alcanzable" or "no devolvió salida" and you want to know *why*.
 */
class DiagnoseRouterWan extends Command
{
    protected $signature   = 'router:diagnose-wan {router_id : ID del router en la BD}';
    protected $description = 'Diagnóstico paso-a-paso de la lectura de interfaces WAN de un router cliente';

    public function handle(): int
    {
        $routerId = (int) $this->argument('router_id');
        $router   = Router::find($routerId);

        if (!$router) {
            $this->error("Router id={$routerId} no existe en la BD.");
            return self::FAILURE;
        }

        $this->info("== Router id={$router->id} name=\"{$router->name}\" ip={$router->ip} firmware={$router->firmware_version} ==");
        $this->line("user_rb={$router->user_rb}  puerto_api=" . ($router->puerto_api ?? 8728));
        $this->newLine();

        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            $this->error('Router sin credenciales (ip / user_rb / password_rb). Aborta.');
            return self::FAILURE;
        }

        $cm = new MikroTikConnectionManager();

        // ── 1. SSH a CORE ────────────────────────────────────────────────────
        $this->info('[1/5] Probando SSH al CORE...');
        $sshTest = $cm->testSshConnection(10);
        $this->line('  success: ' . ($sshTest['success'] ? 'YES' : 'NO'));
        $this->line('  message: ' . ($sshTest['message'] ?? ''));
        if (!empty($sshTest['identity'])) {
            $this->line('  CORE identity: ' . $sshTest['identity']);
        }
        $this->newLine();

        if (!($sshTest['success'] ?? false)) {
            $this->error('Sin SSH al CORE no podemos seguir — corrige primero la conexión servidor→CORE.');
            return self::FAILURE;
        }

        // ── 2. Apertura de túnel SSH al cliente ──────────────────────────────
        $port = (int) ($router->puerto_api ?? 8728);
        $this->info("[2/5] Abriendo túnel SSH CORE → {$router->ip}:{$port}...");

        $tunnelManager = new SshTunnelManager();
        $tunnelOpenError = null;
        $tunnelOpened    = false;
        try {
            $tunnel = $tunnelManager->open($router->ip, $port);
            $tunnelOpened = true;
            $this->line("  túnel abierto en 127.0.0.1:{$tunnel->localPort()} → {$router->ip}:{$port}");
        } catch (\Throwable $e) {
            $tunnelOpenError = $e->getMessage();
            $this->line("  ERROR al abrir túnel: {$tunnelOpenError}");
        }
        $this->newLine();

        // ── 3. TCP probe a través del túnel ──────────────────────────────────
        if ($tunnelOpened) {
            $this->info("[3/5] Probando TCP a {$router->ip}:{$port} a través del túnel...");
            $errno = 0; $errstr = '';
            $probe = @fsockopen($tunnel->localHost(), $tunnel->localPort(), $errno, $errstr, 3);
            if ($probe) {
                @fclose($probe);
                $this->line('  TCP probe: OK — el cliente acepta conexiones en este puerto.');
            } else {
                $this->line("  TCP probe: FAILED — errno={$errno} errstr={$errstr}");
                $this->warn("  → Esto significa que el túnel SSH al CORE funciona, pero el CORE no puede establecer TCP al cliente en {$port}.");
                $this->warn('    Verifica /ip service en el cliente, o que el cliente esté online en el overlay L2TP/SSTP.');
            }
            $tunnel->close();
            $this->newLine();
        } else {
            $this->warn('[3/5] Saltado (no se pudo abrir el túnel).');
            $this->newLine();
        }

        // ── 4. Lectura de interfaces (todo el flujo completo) ────────────────
        $this->info('[4/5] Ejecutando flujo completo getRouterInterfaces() (API directa → SSH-vía-CORE)...');
        $sshService = new MikroTikSshService();
        $result = $sshService->getRouterInterfaces(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $port,
            $router->firmware_version
        );

        $this->line('  success: ' . ($result['success'] ? 'YES' : 'NO'));
        $this->line('  method:  ' . ($result['method'] ?? '(none)'));
        $this->line('  message: ' . ($result['message'] ?? ''));
        if (!empty($result['hint'])) {
            $this->line('  hint:    ' . $result['hint']);
        }
        if (!empty($result['attempts'])) {
            $this->line('  attempts:');
            foreach ($result['attempts'] as $i => $a) {
                $tag = $a['success'] ? 'OK' : 'FAIL';
                $port = $a['port'] ?? '-';
                $this->line(sprintf('    %d. [%s] %s (puerto %s): %s', $i + 1, $tag, $a['method'], $port, $a['message']));
            }
        }
        if (!empty($result['interfaces'])) {
            $this->line('  interfaces obtenidas:');
            foreach ($result['interfaces'] as $iface) {
                $this->line(sprintf('    - %s (type=%s, running=%s, disabled=%s)',
                    $iface['name'], $iface['type'],
                    $iface['running'] ? 'yes' : 'no',
                    $iface['disabled'] ? 'yes' : 'no'
                ));
            }
        }
        $this->newLine();

        // ── 5. Si todo el flujo falló, ejecuta los comandos crudos manualmente
        if (!$result['success']) {
            $this->info('[5/5] Ejecutando los 3 comandos ssh-exec en crudo para ver la respuesta literal de RouterOS...');
            $variants = $this->buildVariantsForDebug($router->ip, $router->user_rb, $router->password_rb);
            foreach ($variants as $name => $cmd) {
                $this->line('');
                $this->line("  ── variant: {$name} ──");
                $this->line('  comando enviado:');
                $this->line('    ' . str_replace("\n", "\n    ", $cmd));
                $r = $cm->executeSsh($cmd);
                if (!($r['success'] ?? false)) {
                    $this->line('  ERROR ejecutando: ' . ($r['message'] ?? '(sin mensaje)'));
                    continue;
                }
                $raw = (string) ($r['output'] ?? '');
                $this->line("  raw output (length={" . strlen($raw) . "}):");
                if ($raw === '') {
                    $this->line('    (vacío)');
                } else {
                    $this->line('    ' . str_replace("\n", "\n    ", substr($raw, 0, 1500)));
                    if (strlen($raw) > 1500) {
                        $this->line('    ...(truncado a 1500 chars)');
                    }
                }
            }
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Mirror of InterfaceReader::buildCoreInterfaceCommandVariants. Duplicated here
     * because that method is private — keeping it private in the reader is the right
     * encapsulation, and this debug command is one of the few callers that needs to
     * peek at the raw command shapes.
     */
    private function buildVariantsForDebug(string $clientIp, string $clientUser, string $clientPass): array
    {
        $clientCommand = '/interface ethernet print terse';

        $ip   = addcslashes($clientIp, "\\\"");
        $user = addcslashes($clientUser, "\\\"");
        $pass = addcslashes($clientPass, "\\\"");
        $cmd  = addcslashes($clientCommand, "\\\"");

        $variantTostr =
            ':put "ISP_BEGIN"; ' .
            ':local r ""; :local ec -1; ' .
            ':do { ' .
                ':local res [/system ssh-exec address="' . $ip . '" user="' . $user . '" password="' . $pass . '" command="' . $cmd . '"]; ' .
                ':set r [:tostr $res]; :set ec 0 ' .
            '} on-error={ :set r "ISP_FAIL"; :set ec 1 }; ' .
            ':put $r; :put ("ISP_END:" . [:tostr $ec])';

        $variantOutputField =
            ':put "ISP_BEGIN"; ' .
            ':local r ""; :local ec -1; ' .
            ':do { ' .
                ':local res [/system ssh-exec address="' . $ip . '" user="' . $user . '" password="' . $pass . '" command="' . $cmd . '"]; ' .
                ':set r ($res->"output"); :set ec ($res->"exit-code") ' .
            '} on-error={ :set r "ISP_FAIL"; :set ec 1 }; ' .
            ':put $r; :put ("ISP_END:" . [:tostr $ec])';

        $variantLegacy = sprintf(
            '/system ssh-exec address="%s" user="%s" password="%s" command="%s"',
            $ip, $user, $pass, $cmd
        );

        return [
            'tostr_envelope'        => $variantTostr,
            'output_field_envelope' => $variantOutputField,
            'legacy_autoprint'      => $variantLegacy,
        ];
    }
}
