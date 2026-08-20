<?php

namespace App\Support;

use Throwable;

/**
 * Distingue un fallo de INFRAESTRUCTURA de la base de datos —no hay conexión,
 * credenciales inválidas, el servidor no acepta más clientes— de un error de
 * DATOS —una restricción violada, una columna que no existe—.
 *
 * POR QUÉ EXISTE
 * El 20 de agosto de 2026 la contraseña de Postgres quedó desactualizada tras
 * una rotación de credenciales y toda `QueryException` se respondía igual: 422
 * con «Ocurrió un error al procesar tu solicitud» y, en peticiones web, un
 * `redirect()->back()`. Sin base de datos no hay sesión, y `back()` sin sesión
 * ni Referer cae a la raíz del sitio (`UrlGenerator::previous()`), así que cada
 * intento reproducía el error y volvía a redirigir: bucle infinito. El 422,
 * además, decía «tus datos están mal» cuando la base ni siquiera respondía.
 * Entre el bucle y el mensaje equivocado, el diagnóstico tomó horas.
 *
 * La distinción importa porque las respuestas correctas son opuestas:
 *
 *   Error de datos  → 4xx. Es la petición la que está mal; corregirla la
 *                     arregla, y la sesión sigue viva, así que redirigir es
 *                     seguro.
 *   Fallo de infra  → 503. Es el servidor el que está mal; no hay nada que el
 *                     usuario pueda corregir, no se puede confiar en la sesión,
 *                     y hay que despertar a alguien.
 *
 * Ver `docs/BITACORA_TECNICA.md`, entrada del 2026-08-20.
 */
class DatabaseFailure
{
    /**
     * Clases de SQLSTATE (los dos primeros caracteres) que significan «no pude
     * hablar con la base de datos».
     *
     *   08  connection_exception — la clase entera: 08000, 08001, 08004, 08006.
     *       Es la que produjo el incidente.
     *   28  invalid_authorization_specification — 28P01 es exactamente
     *       «password authentication failed».
     *   53  insufficient_resources — 53300 es «too many connections», que en un
     *       pooler compartido deja el servicio tan caído como no tener clave.
     *   57  operator_intervention — 57P01 admin_shutdown, 57P03 cannot_connect_now
     *       (el servidor está arrancando y todavía no acepta consultas).
     *
     * Deliberadamente NO incluye 42 (undefined_table / undefined_column): una
     * migración que falta es un error de despliegue, no de conectividad, y
     * responder 503 a eso escondería el problema real detrás de «vuelve luego».
     */
    private const INFRASTRUCTURE_CLASSES = ['08', '28', '53', '57'];

    /**
     * Fragmentos de mensaje para los casos en que no llega un SQLSTATE usable.
     *
     * Un fallo al CONECTAR ocurre antes de que exista una sentencia, así que
     * `errorInfo` puede venir vacío y el código quedar en 0. El texto del driver
     * es entonces la única señal disponible. En minúsculas: la comparación se
     * hace sobre el mensaje pasado a minúsculas.
     */
    private const INFRASTRUCTURE_HINTS = [
        'could not connect',
        'connection refused',
        'connection to server',
        'no connection to the server',
        'server closed the connection',
        'password authentication failed',
        'could not find driver',
        'connection timed out',
        'no pg_hba.conf entry',
        'terminating connection',
        'the database system is starting up',
        'too many connections',
        'connection reset by peer',
        'gone away',
    ];

    /**
     * ¿Este error significa que la base de datos no está disponible?
     */
    public static function isInfrastructure(Throwable $e): bool
    {
        foreach (self::sqlStates($e) as $state) {
            if (in_array(substr($state, 0, 2), self::INFRASTRUCTURE_CLASSES, true)) {
                return true;
            }
        }

        $message = strtolower($e->getMessage());

        foreach (self::INFRASTRUCTURE_HINTS as $hint) {
            if (str_contains($message, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reúne todos los SQLSTATE que se puedan extraer de la excepción.
     *
     * Se miran tres fuentes y la cadena de excepciones anteriores porque ninguna
     * es fiable por sí sola: en un fallo de conexión Laravel envuelve una
     * PDOException cuyo `getCode()` trae el SQLSTATE como cadena, mientras que en
     * un fallo de consulta el dato vive en `errorInfo[0]` y el código puede venir
     * en 0. El texto `SQLSTATE[xxxxx]` del mensaje es el último recurso.
     *
     * @return list<string>
     */
    private static function sqlStates(Throwable $e): array
    {
        $states = [];
        $current = $e;
        $depth = 0;

        // Tope de profundidad: la cadena de `previous` la construyen terceros y
        // no hay garantía de que no venga en ciclo.
        while ($current !== null && $depth < 5) {
            $code = $current->getCode();

            if (is_string($code) && $code !== '') {
                $states[] = $code;
            }

            $errorInfo = property_exists($current, 'errorInfo') ? $current->errorInfo : null;

            if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0])) {
                $states[] = $errorInfo[0];
            }

            if (preg_match_all('/SQLSTATE\[([0-9A-Za-z]{5})\]/', $current->getMessage(), $matches)) {
                foreach ($matches[1] as $match) {
                    $states[] = $match;
                }
            }

            $current = $current->getPrevious();
            $depth++;
        }

        return $states;
    }
}
