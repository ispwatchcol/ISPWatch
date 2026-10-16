<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Clear the application cache.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCache()
    {
        try {
            // Run the optimize:clear command which clears:
            // - Events
            // - Views
            // - Cache
            // - Route
            // - Config
            // - Compiled
            Artisan::call('optimize:clear');

            return response()->json([
                'success' => true,
                'message' => 'System cache cleared successfully',
                'output' => Artisan::output()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear cache: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Versión del producto que está corriendo en este despliegue.
     *
     * POR QUÉ ES UN ENDPOINT Y NO UN TEXTO EN EL FRONTEND
     * El número vivía escrito a mano dentro de `Settings.vue` y decía `v1.0.0`
     * pasara lo que pasara: la pantalla no informaba de la versión, informaba de
     * lo que alguien tecleó una vez. Un dato que no puede estar equivocado —
     * porque lo primero que pregunta soporte es «¿qué versión te aparece?»—
     * tiene que venir del servidor que atiende, no del bundle del navegador,
     * que además puede estar cacheado de un despliegue anterior.
     *
     * SIN PERMISO A PROPÓSITO
     * Saber qué versión usa uno mismo no es un dato reservado, y exigir
     * `view_settings` dejaría sin poder responder esa pregunta justamente al
     * usuario que llama a soporte.
     */
    /**
     * Estado real del sistema para el tile «Estado del Sistema» (P-33 / KAN-68).
     *
     * Antes el tile decía «Operativo» con texto fijo, aunque el planificador
     * estuviera caído. Ahora refleja la señal que de verdad hace falta: si el
     * planificador sigue latiendo, porque de él depende todo el ciclo de
     * facturación, recordatorios y cortes. Lee la MISMA clave y el MISMO umbral
     * que `/health` (config/health.php), así que el tile y el centinela externo
     * no se pueden contradecir.
     *
     * No expone nada sensible: sólo el estado y cuántos segundos lleva sin
     * latir. Abierto a cualquier autenticado, igual que la versión.
     */
    public function status()
    {
        if (! config('health.scheduler.expected')) {
            return response()->json(['scheduler' => ['status' => 'not_expected']]);
        }

        $last = \Illuminate\Support\Facades\Cache::get(config('health.scheduler.cache_key'));
        $maxSilence = (int) config('health.scheduler.max_silence_seconds');

        if ($last === null) {
            return response()->json(['scheduler' => [
                'status'              => 'never',
                'max_silence_seconds' => $maxSilence,
            ]]);
        }

        $silence = max(0, now()->getTimestamp() - (int) $last);

        return response()->json(['scheduler' => [
            'status'               => $silence > $maxSilence ? 'stale' : 'ok',
            'last_run_seconds_ago' => $silence,
            'max_silence_seconds'  => $maxSilence,
        ]]);
    }

    public function version()
    {
        return response()->json([
            'version'     => config('version.number'),
            'released_at' => config('version.released_at'),
            'build'       => $this->buildId(),
        ]);
    }

    /**
     * Identificador del BUNDLE que está sirviendo este despliegue (KAN-101).
     *
     * `version` no sirve para detectar un despliegue: la mayoría de los
     * despliegues corrigen algo sin mover el número de SemVer, y el navegador
     * que tuviera la aplicación abierta seguiría con el código viejo sin que
     * nada se lo dijera. El manifiesto de Vite, en cambio, cambia SIEMPRE que
     * cambia un chunk — es la lista de los nombres con hash.
     *
     * El frontend guarda el primer `build` que ve al cargar y compara los
     * siguientes contra ése: si cambia, hay código nuevo en el servidor.
     *
     * Devuelve null cuando no hay manifiesto (desarrollo con el dev server de
     * Vite), y entonces el aviso simplemente no aparece: allí está el HMR.
     */
    private function buildId(): ?string
    {
        static $buildId = false;

        if ($buildId !== false) {
            return $buildId;
        }

        $manifest = public_path('build/manifest.json');

        return $buildId = is_file($manifest)
            ? substr((string) sha1_file($manifest), 0, 12)
            : null;
    }
}
