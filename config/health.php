<?php

/*
|--------------------------------------------------------------------------
| Chequeo de salud profundo
|--------------------------------------------------------------------------
|
| Configura `GET /health`, el endpoint que un monitor EXTERNO consulta
| para saber si ISPWatch está realmente operativo.
|
| POR QUÉ NO BASTA CON `/up`
| `/up` es el chequeo por defecto de Laravel: responde 200 mientras el proceso
| PHP siga vivo, sin tocar la base de datos. Durante la caída del 2026-08-20
| devolvió 200 las quince horas que el sistema estuvo inutilizable, y por eso
| nadie recibió una alerta. `/health` verifica cada dependencia de verdad.
|
| LA DIVISIÓN ES DELIBERADA
| `/up` se queda superficial porque lo consulta el orquestador para decidir
| reinicios, y reiniciar un contenedor no arregla una contraseña equivocada —
| solo produce un ciclo de reinicios. `/health` es para humanos y para el
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
        | Si es true, la ausencia de latido cuenta como fallo.
        |
        | Debe quedar en true en producción, y con el despliegue actual más que
        | nunca: el planificador no es un componente propio, corre de fondo dentro
        | del `worker` (`schedule:work &` justo antes de que `exec queue:work` tome
        | el proceso principal). Si ese proceso de fondo muere, el contenedor sigue
        | vivo —el principal es la cola—, App Platform lo ve sano, y deja de
        | ocurrir todo el ciclo automático sin que nada falle de forma visible.
        |
        | Ponerlo en false sólo tiene sentido donde a propósito no corre el
        | planificador.
        */
        'expected' => env('HEALTH_SCHEDULER_EXPECTED', true),

        /*
        | El planificador late cada minuto (`system:heartbeat`). Cinco minutos
        | de silencio dan margen para un despliegue o un tick perdido sin
        | tolerar que esté realmente caído.
        */
        'max_silence_seconds' => env('HEALTH_SCHEDULER_MAX_SILENCE', 300),

        'cache_key' => 'health:scheduler:last_run',

        /*
        | URL de «sigo vivo» de Healthchecks.io (https://hc-ping.com/<uuid>).
        |
        | POR QUÉ UN SEGUNDO PROVEEDOR Y NO MÁS DE LO MISMO
        | El centinela de UptimeRobot consulta `/health` desde fuera, y eso cubre
        | «la aplicación no responde». Pero no cubre dos cosas:
        |
        |   1. Que UptimeRobot deje de funcionar. No avisa de su propio silencio;
        |      nadie vigila al vigilante.
        |   2. Que el planificador muera mientras la web sigue en pie. Ahí
        |      `/health` responde 503 y UptimeRobot sí lo ve — pero sólo mientras
        |      el servicio web esté vivo para contarlo.
        |
        | Healthchecks invierte la dirección: en vez de que alguien pregunte desde
        | fuera, es el planificador quien avisa. Si deja de avisar, salta la
        | alarma. Son dos proveedores independientes cubriéndose mutuamente, y la
        | señal viaja por caminos opuestos.
        |
        | Sin definir, no se envía nada y el latido local sigue funcionando igual.
        */
        'ping_url' => env('HEALTHCHECKS_PING_URL'),
    ],

    'queue' => [
        // Trabajos encolados por encima de esto = el worker no da abasto o murió.
        'max_pending' => env('HEALTH_QUEUE_MAX_PENDING', 500),

        // Un trabajo esperando más de esto = el worker no los está tomando.
        'max_age_seconds' => env('HEALTH_QUEUE_MAX_AGE', 900),
    ],

];
