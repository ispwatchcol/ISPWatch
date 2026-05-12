<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Router;
use App\Services\MikroTik\MikroTikApiProtocol;
use App\Services\MikroTik\SshTunnelManager;

/**
 * Deep API diagnostic command — connects to a client router exactly the way the
 * Configure WAN modal does, then logs every single API word sent and received so
 * we can pinpoint why /interface/ethernet/print is returning 0 records.
 *
 * Usage:
 *   php artisan app:debug-router-api 1
 *   php artisan app:debug-router-api 1 --ip=172.16.16.254 --user=ispwatch --pass=xxx --port=8728
 */
class DebugRouterApi extends Command
{
    protected $signature = 'app:debug-router-api
        {router_id? : Router ID stored in DB}
        {--ip= : Override IP}
        {--user= : Override user}
        {--pass= : Override password}
        {--port=8728 : Override port}';

    protected $description = 'Trace the full MikroTik API conversation when fetching interfaces from a client router';

    public function handle(): int
    {
        $routerId = $this->argument('router_id');
        $router = $routerId ? Router::find($routerId) : null;

        $ip = $this->option('ip') ?: optional($router)->ip;
        $user = $this->option('user') ?: optional($router)->user_rb;
        $pass = $this->option('pass') ?: optional($router)->password_rb;
        $port = (int) ($this->option('port') ?: (optional($router)->puerto_api ?? 8728));

        if (!$ip || !$user || !$pass) {
            $this->error('Missing credentials. Provide a router_id or --ip --user --pass.');
            return 1;
        }

        $logFile = base_path('debug_router_api.log');
        @file_put_contents($logFile, '');

        $log = function (string $line) use ($logFile): void {
            $stamped = '[' . date('H:i:s') . '] ' . $line;
            $this->line($stamped);
            file_put_contents($logFile, $stamped . PHP_EOL, FILE_APPEND);
        };

        $log("====================================================");
        $log("MikroTik API deep diagnostic");
        $log("====================================================");

        if ($router) {
            $log("Router row id={$router->id} name={$router->name}");
            $log("  DB ip            = {$router->ip}");
            $log("  DB puerto_api    = " . ($router->puerto_api ?? 'null'));
            $log("  DB user_rb       = {$router->user_rb}");
            $log("  DB password_rb   = " . ($router->password_rb ? '(present, ' . strlen($router->password_rb) . ' chars)' : 'EMPTY'));
            $log("  DB wan_interface = " . ($router->wan_interface ?? 'null'));
        }

        $log("Connecting to {$ip}:{$port} as '{$user}' via SSH tunnel through CORE");

        // ── Step 0: open SSH local-forward tunnel through the CORE ──────────
        // Client routers live behind the L2TP overlay; the production server
        // can't reach $ip directly, so we forward 127.0.0.1:<rand> -> $ip:$port.
        $tunnelManager = new SshTunnelManager();
        try {
            $tunnel = $tunnelManager->open($ip, $port);
        } catch (\Throwable $e) {
            $log("❌ SSH tunnel open failed: " . $e->getMessage());
            $log("   Verify MIKROTIK_CORE_SSH_KEY_B64 is set in App Platform envs and");
            $log("   that the corresponding public key is installed on the MikroTik CORE.");
            return 1;
        }
        $log("✓ Tunnel opened: 127.0.0.1:{$tunnel->localPort()} -> CORE -> {$ip}:{$port}");

        $localHost = $tunnel->localHost();
        $localPort = $tunnel->localPort();

        // ── Step 1: raw TCP probe through the tunnel ───────────────────────
        $tcp = @fsockopen($localHost, $localPort, $errno, $errstr, 5);
        if (!$tcp) {
            $log("❌ TCP probe failed through tunnel: [{$errno}] {$errstr}");
            $log("   Tunnel is up locally but the CORE could not reach {$ip}:{$port}.");
            $tunnel->close();
            return 1;
        }
        $log("✓ TCP port {$port} reachable through tunnel");
        fclose($tcp);

        // ── Step 2: open API socket through the tunnel with tracing protocol ─
        $protocol = new TracingApiProtocol($log);
        $socket = $protocol->connect($localHost, $localPort, 10);
        if (!$socket) {
            $log("❌ API socket connect failed");
            $tunnel->close();
            return 1;
        }
        $log("✓ API socket opened");

        // ── Step 3: login (manual, byte-level traced) ───────────────────────
        $log("---- LOGIN (manual byte-level trace) ----");
        $protocol->sendCommand($socket, '/login', [
            '=name=' . $user,
            '=password=' . $pass,
        ]);

        $log("Socket meta after sending /login:");
        $meta = stream_get_meta_data($socket);
        $log("  timed_out=" . ($meta['timed_out'] ? 'yes' : 'no') .
             " eof=" . ($meta['eof'] ? 'yes' : 'no') .
             " blocked=" . ($meta['blocked'] ? 'yes' : 'no'));

        $log("Reading login response with raw byte dump...");
        $rawBytes = '';
        $deadline = microtime(true) + 5.0;
        stream_set_timeout($socket, 5);

        while (microtime(true) < $deadline) {
            $byte = @fread($socket, 1024);
            if ($byte === false) {
                $log("  fread returned false (socket error or closed)");
                break;
            }
            if ($byte === '') {
                $meta = stream_get_meta_data($socket);
                if ($meta['eof']) {
                    $log("  fread returned empty + EOF=yes → router closed connection");
                    break;
                }
                if ($meta['timed_out']) {
                    $log("  fread returned empty + timed_out=yes");
                    break;
                }
                usleep(50000);
                continue;
            }
            $rawBytes .= $byte;
            if (strlen($rawBytes) > 4) {
                break;
            }
        }

        $log("Raw bytes received: " . strlen($rawBytes) . " bytes → hex: " . bin2hex($rawBytes));
        $log("As text: " . json_encode($rawBytes));

        if (strlen($rawBytes) === 0) {
            $log("❌ Router closed the connection without ANY response. This usually means:");
            $log("   - 'Available From' on /ip service api blocks the server's IP");
            $log("   - The router has fail2ban / blacklist active against this server");
            $log("   - There's a firewall between server and router dropping API traffic");
            $protocol->close($socket);
            $tunnel->close();
            return 1;
        }

        // Try to interpret the first bytes as RouterOS API words
        $firstLen = ord($rawBytes[0]);
        $log("First byte = 0x" . sprintf('%02x', $firstLen) . " → word length indicator: {$firstLen}");
        if (strlen($rawBytes) >= 1 + $firstLen) {
            $firstWord = substr($rawBytes, 1, $firstLen);
            $log("First word decoded: '{$firstWord}'");
            if ($firstWord === '!trap') {
                $log("⚠ Router replied !trap — authentication rejected");
            } elseif ($firstWord === '!done') {
                $log("✓ Router replied !done — login accepted");
            }
        }

        $protocol->close($socket);
        $tunnel->close();
        $log("====================================================");
        $log("Stopping here — fix login first before testing /interface");
        $log("Full transcript: {$logFile}");
        return 0;

        // ── Step 4: identify the router we are actually talking to ──────────
        $log("---- /system/identity/print ----");
        $protocol->sendCommand($socket, '/system/identity/print');
        $result = $protocol->readAllRecordsWithStatus($socket);
        $log("Records: " . count($result['records']) . ($result['trap'] ? " | trap={$result['trap']}" : ''));
        foreach ($result['records'] as $i => $rec) {
            $log("  record[{$i}] = " . json_encode($rec));
        }

        // ── Step 5: who am I according to the router ────────────────────────
        $log("---- /user/active/print ----");
        $protocol->sendCommand($socket, '/user/active/print');
        $result = $protocol->readAllRecordsWithStatus($socket, 4000);
        $log("Records: " . count($result['records']) . ($result['trap'] ? " | trap={$result['trap']}" : ''));
        foreach ($result['records'] as $i => $rec) {
            $log("  record[{$i}] = " . json_encode($rec));
        }

        // ── Step 6: targeted ethernet listing ───────────────────────────────
        $log("---- /interface/ethernet/print ----");
        $protocol->sendCommand($socket, '/interface/ethernet/print');
        $result = $protocol->readAllRecordsWithStatus($socket, 20000);
        $log("Records: " . count($result['records']) . ($result['trap'] ? " | trap={$result['trap']}" : ''));
        foreach ($result['records'] as $i => $rec) {
            $log("  record[{$i}] = " . json_encode($rec));
        }

        // ── Step 7: same but with explicit proplist (some versions need it) ─
        $log("---- /interface/ethernet/print =.proplist=name,running,disabled,comment ----");
        $protocol->sendCommand($socket, '/interface/ethernet/print', [
            '=.proplist=name,running,disabled,comment',
        ]);
        $result = $protocol->readAllRecordsWithStatus($socket, 20000);
        $log("Records: " . count($result['records']) . ($result['trap'] ? " | trap={$result['trap']}" : ''));
        foreach ($result['records'] as $i => $rec) {
            $log("  record[{$i}] = " . json_encode($rec));
        }

        // ── Step 8: generic interface list with high maxWords ───────────────
        $log("---- /interface/print (first 30 records dumped) ----");
        $protocol->sendCommand($socket, '/interface/print');
        $result = $protocol->readAllRecordsWithStatus($socket, 50000);
        $log("Records: " . count($result['records']) . ($result['trap'] ? " | trap={$result['trap']}" : ''));
        foreach (array_slice($result['records'], 0, 30) as $i => $rec) {
            $log("  record[{$i}] = " . json_encode([
                'name' => $rec['name'] ?? null,
                'type' => $rec['type'] ?? null,
                'running' => $rec['running'] ?? null,
                'disabled' => $rec['disabled'] ?? null,
                'dynamic' => $rec['dynamic'] ?? null,
            ]));
        }
        if (count($result['records']) > 30) {
            $log("  ... (" . (count($result['records']) - 30) . " more records omitted)");
        }

        $protocol->close($socket);
        $tunnel->close();
        $log("====================================================");
        $log("Done. Full transcript: {$logFile}");
        $log("====================================================");

        return 0;
    }
}

/**
 * Drop-in subclass that logs every word read and written. Keeps the protocol
 * implementation untouched so a bug here can't influence the production path.
 */
class TracingApiProtocol extends MikroTikApiProtocol
{
    /** @var callable */
    private $log;

    public function __construct(callable $log)
    {
        $this->log = $log;
    }

    public function writeWord($socket, string $word): void
    {
        ($this->log)("    → SEND '" . $this->preview($word) . "' (" . strlen($word) . " bytes)");
        parent::writeWord($socket, $word);
    }

    public function readWord($socket): string
    {
        $word = parent::readWord($socket);
        ($this->log)("    ← RECV '" . $this->preview($word) . "' (" . strlen($word) . " bytes)");
        return $word;
    }

    private function preview(string $word): string
    {
        if ($word === '') {
            return '<empty / sentence terminator>';
        }
        if (stripos($word, 'password') !== false) {
            return '=password=***';
        }
        if (str_starts_with($word, '=response=')) {
            return '=response=***';
        }
        return strlen($word) > 120 ? substr($word, 0, 120) . '…' : $word;
    }
}
