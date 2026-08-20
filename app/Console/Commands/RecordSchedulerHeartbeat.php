<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        // Primero el latido local. Si esto falla —la base de datos es el almacén
        // del caché— la excepción sube, el comando se marca como fallido y NO se
        // envía el ping externo. Es la semántica correcta: el aviso hacia afuera
        // significa «sigo vivo y además funciono», no sólo «este proceso existe».
        Cache::put($key, now()->getTimestamp(), $ttl);

        $this->pingExterno();

        return self::SUCCESS;
    }

    /**
     * Avisa a Healthchecks.io de que el planificador sigue corriendo.
     *
     * La alarma salta por AUSENCIA: si estos avisos dejan de llegar, Healthchecks
     * notifica. Es lo contrario de un monitor que pregunta desde fuera, y por eso
     * detecta un caso que el otro no puede — el planificador muerto dentro de un
     * contenedor que la plataforma sigue viendo sano.
     *
     * Un fallo de red aquí NO hace fallar el comando. Si el ping no sale, el
     * propio Healthchecks lo notará por el silencio; hacer fallar la tarea cada
     * minuto por un blip de red sólo llenaría el log de errores que no lo son.
     */
    private function pingExterno(): void
    {
        $url = config('health.scheduler.ping_url');

        if (blank($url)) {
            return;
        }

        try {
            // Timeout corto: el planificador tiene que seguir con lo suyo. Este
            // aviso es lo menos urgente que hará en todo el minuto.
            Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar a Healthchecks: ' . $e->getMessage());
        }
    }
}
