<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chequeo de salud profundo para el centinela externo.
 *
 * Verifica cada dependencia de verdad y devuelve el detalle por componente, a
 * diferencia de `/up`, que responde 200 mientras el proceso PHP siga en pie.
 * Ver `config/health.php` para el porqué de esa división.
 *
 * DOS REGLAS QUE NO SE PUEDEN ROMPER
 *
 *  1. Nada de lo que hay aquí puede lanzar una excepción. Un chequeo de salud
 *     que revienta cuando algo falla no informa: se convierte en un 500 opaco
 *     justo cuando más falta hace el detalle. Cada verificación va aislada en
 *     su try/catch y un fallo se reporta, no se propaga.
 *
 *  2. La respuesta no lleva secretos. Ni cadenas de conexión, ni credenciales,
 *     ni rutas del sistema de archivos. Solo nombres de componente, estado y
 *     números. El endpoint puede quedar abierto precisamente por eso.
 */
class HealthController extends Controller
{
    /**
     * Estado que devuelve un chequeo que no aplica en este entorno.
     * No cuenta como fallo: no tenerlo configurado no es estar caído.
     */
    private const SKIPPED = 'skipped';

    private const OK = 'ok';
    private const FAIL = 'fail';

    public function check(Request $request): JsonResponse
    {
        if (! $this->tokenIsValid($request)) {
            // 404 y no 403: si el endpoint está protegido, tampoco conviene
            // confirmarle a quien no tiene el token que existe.
            return response()->json(['message' => 'Not found.'], 404);
        }

        $checks = [
            'database' => $this->checkDatabase(),
        ];

        // Los tres siguientes necesitan la base de datos o no dicen nada útil
        // sin ella. Si ya sabemos que está caída, marcarlos como fallidos sería
        // ruido: el problema es uno solo y ya está reportado.
        $databaseUp = $checks['database']['status'] === self::OK;

        $checks['cache']      = $this->checkCache();
        $checks['queue']      = $databaseUp ? $this->checkQueue() : $this->skip('base de datos no disponible');
        $checks['scheduler']  = $databaseUp ? $this->checkScheduler() : $this->skip('base de datos no disponible');
        $checks['migrations'] = $databaseUp ? $this->checkMigrations() : $this->skip('base de datos no disponible');

        $failed = array_keys(array_filter(
            $checks,
            fn (array $check): bool => $check['status'] === self::FAIL
        ));

        $healthy = $failed === [];

        return response()->json([
            'status'    => $healthy ? self::OK : 'degraded',
            'version'   => config('version.number'),
            'timestamp' => now()->toIso8601String(),
            'failing'   => $failed,
            'checks'    => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * ¿Responde Postgres? Es la única pregunta que importa, y `select 1` la
     * contesta sin depender de que ninguna tabla exista.
     */
    private function checkDatabase(): array
    {
        return $this->timed(function (): array {
            DB::select('select 1');

            return ['status' => self::OK];
        });
    }

    /**
     * Escribir y volver a leer. Un `get` a secas no distingue «el caché
     * funciona» de «el caché devuelve null para todo».
     */
    private function checkCache(): array
    {
        return $this->timed(function (): array {
            $key = 'health:probe:' . Str::random(12);
            $value = (string) now()->getTimestampMs();

            Cache::put($key, $value, 10);
            $readBack = Cache::get($key);
            Cache::forget($key);

            if ($readBack !== $value) {
                return [
                    'status' => self::FAIL,
                    'error'  => 'el valor escrito no se pudo leer de vuelta',
                ];
            }

            return ['status' => self::OK, 'store' => config('cache.default')];
        });
    }

    /**
     * Profundidad de la cola y edad del trabajo más viejo.
     *
     * La edad importa más que la cantidad: mil trabajos moviéndose es un pico de
     * carga, pero uno solo esperando quince minutos significa que el worker está
     * muerto — que es exactamente lo que pasó el 2026-08-20 y solo se vio como un
     * badge «Degraded» que nadie miraba.
     */
    private function checkQueue(): array
    {
        if (config('queue.default') !== 'database') {
            return $this->skip('la cola no usa el driver database');
        }

        return $this->timed(function (): array {
            $pending = (int) DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $failed = (int) DB::table('failed_jobs')->count();

            $ageSeconds = $oldest !== null ? max(0, now()->getTimestamp() - (int) $oldest) : null;

            $maxPending = (int) config('health.queue.max_pending');
            $maxAge = (int) config('health.queue.max_age_seconds');

            $problems = [];

            if ($pending > $maxPending) {
                $problems[] = "hay {$pending} trabajos encolados (máximo {$maxPending})";
            }

            if ($ageSeconds !== null && $ageSeconds > $maxAge) {
                $problems[] = "el trabajo más viejo lleva {$ageSeconds}s esperando (máximo {$maxAge}s)";
            }

            return [
                'status'                => $problems === [] ? self::OK : self::FAIL,
                'pending'               => $pending,
                'oldest_pending_seconds' => $ageSeconds,
                'failed'                => $failed,
                'error'                 => $problems === [] ? null : implode('; ', $problems),
            ];
        });
    }

    /**
     * ¿Sigue latiendo el planificador?
     *
     * Este es el chequeo que ningún verificador interno puede hacer por sí mismo:
     * un comando que audita la facturación no puede detectar que el planificador
     * jamás lo ejecutó. Aquí se alerta por SILENCIO — si el latido deja de
     * llegar, algo se rompió aunque nadie haya reportado un error.
     *
     * Lo escribe `system:heartbeat`, agendado cada minuto en `routes/console.php`.
     *
     * IMPRESCINDIBLE CON EL DESPLIEGUE ACTUAL. En producción el planificador no
     * es un componente propio: corre de fondo dentro del `worker`, arrancado con
     * `php artisan schedule:work &` justo antes de que `exec queue:work` tome el
     * proceso principal. Si ese proceso de fondo muere, el contenedor sigue vivo
     * —el principal es la cola—, App Platform lo ve sano y NADA del ciclo
     * automático vuelve a ejecutarse: ni facturas, ni recordatorios, ni cortes.
     * Sin latido, ese fallo es invisible hasta fin de mes.
     */
    private function checkScheduler(): array
    {
        if (! config('health.scheduler.expected')) {
            return $this->skip('no se espera planificador en este entorno');
        }

        return $this->timed(function (): array {
            $last = Cache::get(config('health.scheduler.cache_key'));
            $maxSilence = (int) config('health.scheduler.max_silence_seconds');

            if ($last === null) {
                return [
                    'status' => self::FAIL,
                    'error'  => 'nunca ha latido: el planificador no está corriendo, o no ha llegado a hacerlo desde el último despliegue',
                ];
            }

            $silence = max(0, now()->getTimestamp() - (int) $last);

            if ($silence > $maxSilence) {
                return [
                    'status'              => self::FAIL,
                    'last_run_seconds_ago' => $silence,
                    'error'               => "sin latido hace {$silence}s (máximo {$maxSilence}s)",
                ];
            }

            return ['status' => self::OK, 'last_run_seconds_ago' => $silence];
        });
    }

    /**
     * Migraciones pendientes.
     *
     * Un despliegue que arranca con migraciones sin aplicar es una bomba de
     * tiempo: el código nuevo espera columnas que no existen todavía.
     */
    private function checkMigrations(): array
    {
        return $this->timed(function (): array {
            $migrator = app('migrator');

            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();

            $pending = count(array_diff(array_keys($files), $ran));

            return [
                'status'  => $pending === 0 ? self::OK : self::FAIL,
                'pending' => $pending,
                'error'   => $pending === 0 ? null : "{$pending} migración(es) sin aplicar",
            ];
        });
    }

    /**
     * Ejecuta un chequeo midiendo cuánto tardó y atrapando cualquier fallo.
     *
     * El mensaje de la excepción sí entra en la respuesta: es el dato que
     * durante el incidente estuvo enmascarado detrás de «Ocurrió un error» y
     * costó horas de diagnóstico. Un mensaje de driver no es un secreto —
     * describe el fallo, no las credenciales.
     */
    private function timed(callable $check): array
    {
        $started = microtime(true);

        try {
            $result = $check();
        } catch (Throwable $e) {
            $result = [
                'status' => self::FAIL,
                'error'  => Str::limit($e->getMessage(), 300),
            ];
        }

        $result['latency_ms'] = (int) round((microtime(true) - $started) * 1000);

        return array_filter(
            $result,
            fn ($value): bool => $value !== null
        );
    }

    private function skip(string $reason): array
    {
        return ['status' => self::SKIPPED, 'reason' => $reason];
    }

    /**
     * Comparación en tiempo constante para que el endpoint no filtre el token
     * carácter a carácter.
     */
    private function tokenIsValid(Request $request): bool
    {
        $expected = config('health.token');

        if (blank($expected)) {
            return true;
        }

        $provided = $request->header('X-Health-Token') ?? $request->query('token');

        return is_string($provided) && hash_equals((string) $expected, $provided);
    }
}
