<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Cifra en reposo las credenciales de red que estaban en texto plano.
 *
 * ── Qué había ────────────────────────────────────────────────────────────────
 * `router` tenía DOS juegos de columnas: `user_rb`/`password_rb`/`vpn_*` y unas
 * `*_encrypted`. La migración 2026_05_14_000001 copió el valor con SQL crudo
 * dando por hecho que el cast `encrypted` de Eloquent cifraría al escribir —
 * pero ese cast cifra en la ESCRITURA POR MODELO, no en un UPDATE de SQL. El
 * resultado fue que las columnas `*_encrypted` quedaron con texto plano, y el
 * cast, al intentar descifrarlo en cada lectura, lanzaba DecryptException y
 * rompía la generación de scripts VPN y el aprovisionamiento. Se desactivó el
 * cast y el sistema siguió con las credenciales en claro en ambos juegos.
 *
 * ── Qué hace esta migración ──────────────────────────────────────────────────
 *  1. Ensancha a TEXT las columnas que se van a cifrar. Un valor cifrado por
 *     Laravel ronda los 230-250 caracteres para una cadena corta, así que no
 *     cabe en el varchar(255) original.
 *  2. Cifra EN LA MISMA COLUMNA, con el modelo, no con SQL crudo. Así el nombre
 *     de la propiedad que usa todo el código (`$router->password_rb`) no cambia:
 *     el cast descifra de forma transparente al leer.
 *  3. Elimina las columnas `*_encrypted`, que sólo contenían un duplicado en
 *     claro — es decir, eran un pasivo de seguridad, no una protección.
 *
 * Es idempotente: un valor que ya descifra se deja como está.
 *
 * ── ORDEN DE EJECUCIÓN ───────────────────────────────────────────────────────
 * Ejecutar DESPUÉS de rotar las credenciales expuestas
 * (docs/RUNBOOK_ROTACION_SECRETOS.md). Cifrar credenciales que ya están
 * comprometidas no aporta nada; y rotar APP_KEY DESPUÉS de esta migración
 * obligaría a descifrar y re-cifrar también estos valores.
 *
 * No se cifran los campos por los que se filtra en SQL (`pppoe_username` tiene
 * un índice único por router): un valor cifrado no es consultable.
 */
return new class extends Migration
{
    /** tabla => columnas a cifrar en su sitio. */
    private const A_CIFRAR = [
        'router'           => ['user_rb', 'password_rb', 'vpn_username', 'vpn_password'],
        'sectorial'        => ['pass_rb'],
        'customer_profile' => ['pppoe_password', 'hotspot_password'],
    ];

    /** Columnas duplicadas en claro que se eliminan. */
    private const DUPLICADAS = [
        'router' => [
            'user_rb_encrypted',
            'password_rb_encrypted',
            'vpn_username_encrypted',
            'vpn_password_encrypted',
        ],
    ];

    public function up(): void
    {
        foreach (self::A_CIFRAR as $tabla => $columnas) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            $presentes = array_values(array_filter(
                $columnas,
                fn ($c) => Schema::hasColumn($tabla, $c)
            ));

            if ($presentes === []) {
                continue;
            }

            $this->ensancharATexto($tabla, $presentes);
            $this->cifrarValores($tabla, $presentes);
        }

        $this->eliminarDuplicadasEnClaro();
    }

    /**
     * varchar(255) no admite el valor cifrado. En SQLite (tests) las columnas no
     * tienen longitud efectiva, así que el cambio sólo hace falta en PostgreSQL.
     */
    private function ensancharATexto(string $tabla, array $columnas): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($columnas as $columna) {
            DB::statement("ALTER TABLE {$tabla} ALTER COLUMN {$columna} TYPE TEXT");
        }
    }

    private function cifrarValores(string $tabla, array $columnas): void
    {
        $clave = $this->clavePrimaria($tabla);

        DB::table($tabla)
            ->select(array_merge([$clave], $columnas))
            ->orderBy($clave)
            ->chunk(200, function ($filas) use ($tabla, $columnas, $clave) {
                foreach ($filas as $fila) {
                    $cambios = [];

                    foreach ($columnas as $columna) {
                        $valor = $fila->{$columna} ?? null;

                        if ($valor === null || $valor === '') {
                            continue;
                        }

                        // Ya cifrado (por una ejecución anterior): no tocar.
                        if ($this->yaEstaCifrado($valor)) {
                            continue;
                        }

                        $cambios[$columna] = Crypt::encryptString($valor);
                    }

                    if ($cambios !== []) {
                        DB::table($tabla)->where($clave, $fila->{$clave})->update($cambios);
                    }
                }
            });
    }

    private function yaEstaCifrado(string $valor): bool
    {
        try {
            Crypt::decryptString($valor);
            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /** customer_profile usa user_id como PK; el resto usa id. */
    private function clavePrimaria(string $tabla): string
    {
        return $tabla === 'customer_profile' ? 'user_id' : 'id';
    }

    private function eliminarDuplicadasEnClaro(): void
    {
        foreach (self::DUPLICADAS as $tabla => $columnas) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            $presentes = array_values(array_filter(
                $columnas,
                fn ($c) => Schema::hasColumn($tabla, $c)
            ));

            if ($presentes === []) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($presentes) {
                $table->dropColumn($presentes);
            });
        }
    }

    public function down(): void
    {
        // Descifrar de vuelta a texto plano sería reintroducir a propósito el
        // problema que esta migración corrige. Si hay que revertir, hazlo con un
        // restore del respaldo.
    }
};
