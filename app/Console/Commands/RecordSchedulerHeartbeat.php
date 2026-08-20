<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Deja constancia de que el planificador sigue vivo.
 *
 * POR QUÉ HACE FALTA
 * Hay una clase de fallo que ningún verificador interno puede detectar: que el
 * sistema NO HAYA CORRIDO. Ya pasó dos veces. La primera, el componente
 * `scheduler` no existía en producción y no se generó ninguna factura del mes;
 * el failover calló porque solo sabe ver fallos por cliente, no un proceso que
 * jamás arrancó. La segunda, la caída del 2026-08-20, en la que el planificador
 * estuvo quince horas sin poder hacer nada y nadie se enteró.
 *
 * Un comando que audita la facturación no puede reportar que el planificador no
 * lo ejecutó — para reportarlo tendría que estar corriendo. La única salida es
 * invertir la lógica: el planificador dice «sigo aquí» cada minuto, y quien
 * vigila alerta cuando ese aviso DEJA de llegar. Es un dead man's switch.
 *
 * Lo lee `HealthController::checkScheduler()`, que a su vez consulta el
 * centinela externo. Con eso, un planificador caído se nota en cinco minutos en
 * lugar de descubrirse a fin de mes al ver que no hubo facturas.
 */
class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'system:heartbeat';

    protected $description = 'Registra que el planificador está vivo. Lo consulta /health para alertar por silencio.';

    public function handle(): int
    {
        $key = config('health.scheduler.cache_key');

        // El TTL es el doble del silencio tolerado: si el latido expirara antes
        // de que el chequeo lo considere viejo, un planificador sano se vería
        // como caído durante la ventana intermedia.
        $ttl = ((int) config('health.scheduler.max_silence_seconds')) * 2;

        Cache::put($key, now()->getTimestamp(), $ttl);

        return self::SUCCESS;
    }
}
