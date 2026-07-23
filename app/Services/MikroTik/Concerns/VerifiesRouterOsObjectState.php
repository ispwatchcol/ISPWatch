<?php

namespace App\Services\MikroTik\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Confirma, con una segunda llamada SSH simple (un solo comando `print`, sin
 * anidar `:if`/`:do`), si un objeto RouterOS (secret PPPoE, simple queue)
 * realmente quedó como se esperaba tras un `add+set`.
 *
 * Por qué existe: el patrón `:do { add } on-error={}; set [find ...]` que usan
 * QueueManager/PppProfileManager es "silencioso" ante una colisión de nombre —
 * si el `add` falla porque el nombre ya pertenece a OTRO cliente y el `set`
 * tampoco encuentra nada que tocar (queue: busca por target, no por nombre),
 * RouterOS no imprime ningún error y el código anterior lo interpretaba como
 * éxito. Esta verificación cierra ese hueco sin tocar el shape del comando
 * compuesto (que ya está documentado como "escape-fragile" si se le anida
 * lógica condicional dentro del mismo ssh-exec).
 *
 * Requiere que la clase que use este trait tenga `$this->connectionManager`
 * (MikroTikConnectionManager) y los traits DetectsSshExecFailures y
 * BuildsCoreSshExec — los managers que lo consumen ya cumplen esto.
 */
trait VerifiesRouterOsObjectState
{
    /**
     * Ejecuta un comando `print` simple en el router cliente (vía CORE
     * ssh-exec) y devuelve su salida cruda, distinguiendo un fallo de
     * conexión (nada se pudo verificar) de una respuesta real del router.
     *
     * $clientSshPort debe ser el mismo puerto con el que se hizo el add/set:
     * si el router sirve SSH fuera del 22, un readback al 22 fallaría siempre
     * y reportaría "no se pudo verificar" sobre una carga que sí funcionó.
     */
    protected function verifyRouterOsObject(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $printCommand,
        ?int $clientSshPort = null
    ): array {
        $coreCommand = $this->coreSshExecCommand($clientIp, $clientUser, $clientPass, $printCommand, $clientSshPort);

        $result = $this->connectionManager->executeSsh($coreCommand);

        if (!($result['success'] ?? false)) {
            return [
                'confirmed' => false,
                'connection_ok' => false,
                'output' => null,
                'message' => 'No se pudo verificar el resultado tras el intento — no se pudo conectar al CORE via SSH: '
                    . ($result['message'] ?? 'motivo desconocido'),
            ];
        }

        $output = trim((string) ($result['output'] ?? ''));

        if ($output && $this->isSshExecConnectionFailure($output)) {
            return [
                'confirmed' => false,
                'connection_ok' => false,
                'output' => $output,
                'message' => 'No se pudo verificar el resultado tras el intento — el router dejó de responder: '
                    . $this->sshExecConnectionFailureMessage($clientIp, $output, $clientSshPort),
            ];
        }

        return ['confirmed' => true, 'connection_ok' => true, 'output' => $output, 'message' => null];
    }

    /**
     * Log estructurado con timestamp + duración de un paso de aprovisionamiento
     * (secret / queue / verificación), para poder correlacionar en producción
     * si algo vuelve a acercarse al timeout del job.
     */
    protected function logProvisionStep(string $channel, string $step, array $context, ?float $startedAt = null): void
    {
        $payload = $context;
        if ($startedAt !== null) {
            $payload['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        }
        $payload['step'] = $step;
        $payload['at'] = now()->format('H:i:s.v');

        Log::info("[{$channel}] {$step}", $payload);
    }
}
