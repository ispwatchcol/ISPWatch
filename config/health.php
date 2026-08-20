<?php

/*
|--------------------------------------------------------------------------
| Chequeo de salud profundo
|--------------------------------------------------------------------------
|
| Configura `GET /health/deep`, el endpoint que un monitor EXTERNO consulta
| para saber si ISPWatch está realmente operativo.
|
| POR QUÉ NO BASTA CON `/up`
| `/up` es el chequeo por defecto de Laravel: responde 200 mientras el proceso
| PHP siga vivo, sin tocar la base de datos. Durante la caída del 2026-08-20
| devolvió 200 las quince horas que el sistema estuvo inutilizable, y por eso
| nadie recibió una alerta. `/health/deep` verifica cada dependencia de verdad.
|
| LA DIVISIÓN ES DELIBERADA
| `/up` se queda superficial porque lo consulta el orquestador para decidir
| reinicios, y reiniciar un contenedor no arregla una contraseña equivocada —
| solo produce un ciclo de reinicios. `/health/deep` es para humanos y para el
| centinela externo: informa, no dispara reinicios.
|
*/

return [

    /*
    | Token opcional. Si se define, la petición debe traerlo en la cabecera
    | `X-Health-Token` o en `?token=`. Sin definir, el endpoint queda abierto:
    | no revela secretos —solo nombres de componente y su estado— y así funciona
    | desde el primer despliegue sin configuración extra.
    */
    'token' => env('HEALTH_CHECK_TOKEN'),

    'scheduler' => [
        /*
        | Si es true, la ausencia de latido cuenta como fallo. Debe quedar en
        | true en producción: el componente `scheduler` no estaba desplegado y
        | nadie lo notó, que es precisamente el fallo silencioso que este
        | chequeo existe para delatar. Ponerlo en false solo tiene sentido en
        | entornos donde a propósito no corre el planificador.
        */
        'expected' => env('HEALTH_SCHEDULER_EXPECTED', true),

        /*
        | El planificador late cada minuto (`system:heartbeat`). Cinco minutos
        | de silencio dan margen para un despliegue o un tick perdido sin
        | tolerar que esté realmente caído.
        */
        'max_silence_seconds' => env('HEALTH_SCHEDULER_MAX_SILENCE', 300),

        'cache_key' => 'health:scheduler:last_run',
    ],

    'queue' => [
        // Trabajos encolados por encima de esto = el worker no da abasto o murió.
        'max_pending' => env('HEALTH_QUEUE_MAX_PENDING', 500),

        // Un trabajo esperando más de esto = el worker no los está tomando.
        'max_age_seconds' => env('HEALTH_QUEUE_MAX_AGE', 900),
    ],

];
