<?php

namespace Tests\Unit\Support;

use App\Support\DatabaseFailure;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La distinción entre «la base no responde» y «tus datos están mal».
 *
 * Estas pruebas fijan el comportamiento que faltó el 2026-08-20: una
 * `QueryException` por contraseña inválida se estaba tratando como error de
 * datos, lo que producía un 422 mentiroso y un `redirect()->back()` que sin
 * sesión generaba un bucle infinito de redirecciones.
 *
 * El caso de la primera prueba es el mensaje LITERAL que devolvió Postgres
 * durante el incidente. Si alguna vez vuelve a clasificarse como error de datos,
 * esta prueba se cae.
 */
class DatabaseFailureTest extends TestCase
{
    #[Test]
    public function la_contrasena_invalida_del_incidente_es_fallo_de_infraestructura(): void
    {
        $mensajeReal = 'SQLSTATE[08006] [7] connection to server at "aws-0-us-east-1.pooler.supabase.com" '
            . '(52.45.94.125), port 5432 failed: FATAL:  password authentication failed for user "postgres"';

        $this->assertTrue(
            DatabaseFailure::isInfrastructure($this->queryException($mensajeReal, '08006'))
        );
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('estadosDeInfraestructura')]
    public function los_sqlstate_de_conexion_son_infraestructura(string $sqlState): void
    {
        $this->assertTrue(
            DatabaseFailure::isInfrastructure(
                $this->queryException("SQLSTATE[{$sqlState}] algo salió mal", $sqlState)
            ),
            "El SQLSTATE {$sqlState} debería contar como fallo de infraestructura."
        );
    }

    /** @return array<string, array{string}> */
    public static function estadosDeInfraestructura(): array
    {
        return [
            'connection_exception'      => ['08000'],
            'connection_failure'        => ['08006'],
            'no puede conectar'         => ['08001'],
            'rechazada por el servidor' => ['08004'],
            'contraseña inválida'       => ['28P01'],
            'autorización inválida'     => ['28000'],
            'demasiadas conexiones'     => ['53300'],
            'apagado por el admin'      => ['57P01'],
            'todavía no acepta'         => ['57P03'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('estadosDeDatos')]
    public function los_errores_de_datos_no_son_infraestructura(string $sqlState): void
    {
        $this->assertFalse(
            DatabaseFailure::isInfrastructure(
                $this->queryException("SQLSTATE[{$sqlState}] restricción violada", $sqlState)
            ),
            "El SQLSTATE {$sqlState} es un error de datos y debe seguir respondiendo 422."
        );
    }

    /** @return array<string, array{string}> */
    public static function estadosDeDatos(): array
    {
        return [
            'clave duplicada'      => ['23505'],
            'llave foránea'        => ['23503'],
            'columna obligatoria'  => ['23502'],
            'restricción de check' => ['23514'],
            // 42 queda fuera a propósito: una tabla o columna que falta es un
            // despliegue incompleto, no una caída. Responder 503 lo escondería
            // detrás de «vuelve más tarde» en vez de delatarlo.
            'tabla inexistente'    => ['42P01'],
            'columna inexistente'  => ['42703'],
        ];
    }

    #[Test]
    public function detecta_por_el_texto_cuando_no_hay_sqlstate_utilizable(): void
    {
        // Un fallo al CONECTAR ocurre antes de que exista una sentencia, así que
        // `errorInfo` puede venir vacío y el código en 0. Queda el texto.
        $sinCodigo = new QueryException('pgsql', 'select 1', [], new \Exception('could not find driver'));

        $this->assertTrue(DatabaseFailure::isInfrastructure($sinCodigo));
    }

    #[Test]
    public function encuentra_el_estado_en_la_excepcion_anterior(): void
    {
        $pdo = new PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
        $pdo->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];

        $envuelta = new QueryException('pgsql', 'select 1', [], $pdo);

        $this->assertTrue(DatabaseFailure::isInfrastructure($envuelta));
    }

    #[Test]
    public function una_excepcion_cualquiera_no_es_fallo_de_base_de_datos(): void
    {
        $this->assertFalse(
            DatabaseFailure::isInfrastructure(new \RuntimeException('el router no respondió'))
        );
    }

    private function queryException(string $mensaje, string $sqlState): QueryException
    {
        $pdo = new PDOException($mensaje);
        $pdo->errorInfo = [$sqlState, 0, $mensaje];

        return new QueryException('pgsql', 'select 1', [], $pdo);
    }
}
