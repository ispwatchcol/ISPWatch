<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Qué RouterOS corre un router, leído del campo `router.firmware_version`.
 *
 * Ese campo guarda TRES formatos distintos, todos legítimos y todos en
 * producción hoy:
 *
 *   - la versión cruda: "7.23.1 (stable)", "6.49.10"  → tecleada o importada
 *   - la etiqueta de familia: "v6", "v7"              → es lo que escribe el
 *     formulario de routers, que ofrece un desplegable con las filas de
 *     `script_version` y guarda su TEXTO
 *   - el id de `script_version`: "2" (=v7), "3" (=v6) → filas viejas guardadas
 *     cuando el mismo desplegable enviaba el id
 *
 * Interpretar sólo el primero salía caro: Router::firmwareSupportsWireguard()
 * pedía un "N.N" y devolvía false para "v7", así que un router marcado como v7
 * y con transporte WireGuard recibía en silencio el script L2TP — el operador
 * elegía WireGuard en la UI y se llevaba otra cosa, sin un solo aviso.
 */
class RouterOsVersion
{
    /** id de script_version => etiqueta, memorizado por proceso. */
    private static array $labelCache = [];

    /**
     * Etiqueta legible: resuelve los ids contra `script_version` y deja pasar
     * cualquier otro valor tal cual.
     */
    public static function label(?string $rawValue): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d+$/', $value)) {
            return $value;
        }

        if (array_key_exists($value, self::$labelCache)) {
            return self::$labelCache[$value];
        }

        try {
            $resolved = DB::table('script_version')->where('id', (int) $value)->value('version');
            self::$labelCache[$value] = $resolved !== null ? trim((string) $resolved) : $value;
        } catch (\Throwable $e) {
            // Sin base de datos (pruebas unitarias, consola temprana) el valor
            // crudo sigue siendo la mejor respuesta disponible. El log es
            // mejor-esfuerzo: en esos mismos escenarios la fachada tampoco
            // está montada, y quedarse sin transporte VPN por no poder escribir
            // una advertencia sería absurdo.
            self::$labelCache[$value] = $value;

            try {
                Log::warning('[RouterOsVersion] No se pudo resolver firmware_version desde script_version', [
                    'firmware_value' => $value,
                    'error'          => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // sin logger disponible
            }
        }

        return self::$labelCache[$value];
    }

    /**
     * Rama de RouterOS: 'v6', 'v7' o 'unknown'.
     */
    public static function family(?string $rawValue): string
    {
        $resolved = strtolower(trim((string) (self::label($rawValue) ?? '')));

        if ($resolved === '') {
            return 'unknown';
        }

        if (preg_match('/(^|[^0-9])6(?:[.x]|$)/', $resolved)) {
            return 'v6';
        }

        if (preg_match('/(^|[^0-9])7(?:[.x]|$)/', $resolved)) {
            return 'v7';
        }

        return 'unknown';
    }

    /**
     * ¿Admite WireGuard? Existe desde RouterOS 7.1; en v6 no lo hay.
     *
     * Con una versión numérica manda el número (7.0.x todavía no lo trae). Con
     * una etiqueta de familia manda lo que declaró el operador: si marcó el
     * equipo como v7, degradarlo a L2TP sin decir nada es peor que confiar en
     * él — 7.0.x está extinto y el error, si lo hubiera, se ve en el acto al
     * aplicar el script.
     *
     * Ante lo ilegible: false. L2TP funciona en las dos ramas, así que el
     * fallback seguro es el transporte viejo, nunca el nuevo.
     */
    public static function supportsWireguard(?string $rawValue): bool
    {
        $label = (string) (self::label($rawValue) ?? '');

        if (preg_match('/(\d+)\.(\d+)/', $label, $m)) {
            $major = (int) $m[1];
            $minor = (int) $m[2];

            return $major > 7 || ($major === 7 && $minor >= 1);
        }

        return self::family($rawValue) === 'v7';
    }

    /** Sólo para pruebas: olvida los ids ya resueltos. */
    public static function flushCache(): void
    {
        self::$labelCache = [];
    }
}
