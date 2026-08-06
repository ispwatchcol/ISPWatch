<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\BuildsCoreSshExec;
use App\Services\MikroTik\Concerns\DetectsSshExecFailures;
use Illuminate\Support\Facades\Log;

/**
 * Contrapartida de borrado de todo lo que el aprovisionamiento crea en el
 * router del cliente.
 *
 * Hasta el 2026-08-06 no existía: los managers sólo tenían métodos `ensure*`
 * (crear/actualizar), así que borrar un cliente en ISPWatch le quitaba la
 * ficha pero **le dejaba el servicio funcionando** — el secret PPPoE, la
 * queue, el usuario de HotSpot y el lease seguían en el equipo, y como la
 * ficha ya no existía tampoco quedaba de dónde sacar la IP para ir a
 * limpiarlo a mano. Fuga de ingreso silenciosa.
 *
 * Se ejecuta TODO en un solo ssh-exec y no uno por recurso: cada viaje al
 * CORE cuesta ~15 s y no se sabe de antemano por qué método estaba
 * controlado el cliente (el método del router pudo cambiar después del alta,
 * dejando restos del anterior). Barrer todos los recursos por sus claves —
 * IP, usuario PPPoE, usuario HotSpot, MAC — es más barato y además limpia
 * esos restos.
 *
 * Cada sentencia va envuelta en `:do { } on-error={}` porque en RouterOS un
 * `remove [find ...]` que no encuentra nada es un ERROR, no un no-op: sin el
 * envoltorio, el primer recurso ausente abortaría el resto del barrido.
 */
class CustomerDeprovisionManager
{
    use BuildsCoreSshExec;
    use DetectsSshExecFailures;

    private MikroTikConnectionManager $connectionManager;

    public function __construct(?MikroTikConnectionManager $connectionManager = null)
    {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
    }

    /**
     * Borra del router todo rastro del cliente.
     *
     * @param  array{ip?:?string,pppoe_username?:?string,hotspot_username?:?string,mac_address?:?string} $identity
     * @return array{success:bool,message:string,statements:int,skipped?:bool}
     */
    public function purge(
        string $routerIp,
        string $routerUser,
        string $routerPass,
        array $identity,
        ?int $routerSshPort = null
    ): array {
        $command = $this->buildPurgeCommand($identity);

        if ($command === null) {
            return [
                'success'    => true,
                'skipped'    => true,
                'statements' => 0,
                'message'    => 'El cliente no tenía IP, usuario PPPoE/HotSpot ni MAC: no hay nada que borrar en el router.',
            ];
        }

        $statements = substr_count($command, ':do {');

        try {
            $result = $this->connectionManager->executeSsh(
                $this->coreSshExecCommand($routerIp, $routerUser, $routerPass, $command, $routerSshPort)
            );

            if (!$result['success']) {
                return [
                    'success'    => false,
                    'statements' => $statements,
                    'message'    => $result['message'] ?? 'No se pudo conectar al CORE por SSH.',
                ];
            }

            $output = trim((string) ($result['output'] ?? ''));

            if ($output !== '' && $this->isSshExecConnectionFailure($output)) {
                return [
                    'success'    => false,
                    'statements' => $statements,
                    'message'    => $this->sshExecConnectionFailureMessage($routerIp, $output, $routerSshPort),
                ];
            }

            // A diferencia de un `ensure*`, aquí la salida vacía SÍ es el
            // resultado esperado: un barrido de removes silenciosos no imprime
            // nada. Lo que no se puede distinguir es "borró" de "el ssh-exec
            // nunca llegó", y por eso el fallo de conexión se comprueba antes.
            if ($output !== '' && $this->isSshExecCommandFailure($output)) {
                return [
                    'success'    => false,
                    'statements' => $statements,
                    'message'    => 'El router devolvió un error al limpiar: ' . $output,
                ];
            }

            return [
                'success'    => true,
                'statements' => $statements,
                'message'    => 'Configuración del cliente eliminada del router.',
            ];
        } catch (\Throwable $e) {
            Log::error('[CustomerDeprovision] Excepción limpiando el router', [
                'router_ip' => $routerIp,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'    => false,
                'statements' => $statements,
                'message'    => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Arma el barrido. Devuelve null si el cliente no tiene ninguna clave por
     * la que buscar — sin eso, un `find` sin criterio borraría recursos de
     * OTROS clientes, que es el peor error posible en esta clase.
     */
    private function buildPurgeCommand(array $identity): ?string
    {
        $ip       = $this->cleanIp($identity['ip'] ?? null);
        $pppoe    = $this->cleanName($identity['pppoe_username'] ?? null);
        $hotspot  = $this->cleanName($identity['hotspot_username'] ?? null);
        $mac      = $this->cleanMac($identity['mac_address'] ?? null);

        $stmts = [];

        if ($pppoe !== null) {
            // El `active remove` va primero: sin cortar la sesión viva, el
            // cliente sigue navegando hasta que el enlace se caiga solo.
            $stmts[] = '/ppp active remove [find name="' . $pppoe . '"]';
            $stmts[] = '/ppp secret remove [find name="' . $pppoe . '"]';
            $stmts[] = '/queue simple remove [find name="' . $pppoe . '"]';
        }

        if ($hotspot !== null) {
            $stmts[] = '/ip hotspot active remove [find user="' . $hotspot . '"]';
            $stmts[] = '/ip hotspot user remove [find name="' . $hotspot . '"]';
        }

        if ($ip !== null) {
            $stmts[] = '/queue simple remove [find target=' . $ip . '/32]';
            $stmts[] = '/queue simple remove [find target=' . $ip . ']';
            // Listas de PCQ (por plan) y la de suspendidos por mora.
            $stmts[] = '/ip firewall address-list remove [find address=' . $ip . ']';
            $stmts[] = '/ip firewall address-list remove [find address="' . $ip . '/32"]';
            // IP Bindings (ARP estático) y Amarre (regla drop por par IP/MAC).
            $stmts[] = '/ip arp remove [find address=' . $ip . ']';
            $stmts[] = '/ip firewall filter remove [find comment~"ISPWatch-amarre-' . $ip . '"]';
            $stmts[] = '/ip dhcp-server lease remove [find address=' . $ip . ']';
        }

        if ($mac !== null) {
            $stmts[] = '/ip dhcp-server lease remove [find mac-address=' . $mac . ']';
        }

        if (empty($stmts)) {
            return null;
        }

        // En RouterOS un `remove [find ...]` sin coincidencias es un error que
        // aborta el script. Cada sentencia lleva su propio :do/on-error para
        // que un recurso ausente no impida borrar los demás.
        return implode('; ', array_map(
            fn (string $s) => ':do { ' . $s . ' } on-error={}',
            $stmts
        ));
    }

    /** Sólo una IP válida; cualquier otra cosa se descarta (se interpola sin comillas). */
    private function cleanIp(?string $value): ?string
    {
        $value = trim((string) $value);

        return ($value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false) ? $value : null;
    }

    /** Sólo una MAC con formato canónico; se interpola sin comillas. */
    private function cleanMac(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * SECURITY (OWASP A03): los nombres van dentro de "..." en un script de
     * RouterOS. Se quitan los caracteres de control y se escapan las
     * metacaracteres de cadena entrecomillada — backslash, comilla doble y $
     * (sustitución de variable/comando).
     */
    private function cleanName(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return $value === '' ? null : addcslashes($value, "\\\"\$");
    }
}
