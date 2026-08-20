<?php

/*
|--------------------------------------------------------------------------
| Versión de ISPWatch
|--------------------------------------------------------------------------
|
| FUENTE ÚNICA. Antes el número vivía escrito a mano dentro de un `<p>` de
| `Settings.vue` —decía `v1.0.0` desde siempre, sin relación con lo desplegado—
| y el tag de git más reciente era `v1.0.0-beta`, con 395 commits encima. Es
| decir: nadie podía responder "qué versión tiene el cliente" sin mirar el log.
|
| Qué sí y qué no cambia el número (SemVer, https://semver.org):
|
|   MAYOR  Un ISP tiene que hacer algo para seguir operando: se retira un
|          endpoint de la API pública, cambia el significado de un campo que
|          ya se consumía, o una migración exige intervención manual.
|
|   MENOR  Función nueva compatible hacia atrás. Es lo normal aquí.
|
|   PARCHE Corrección de un fallo, sin función nueva.
|
| ATENCIÓN: esta versión NO es la de la API partner. `/api/v1/partner` tiene su
| propio ciclo y su propio contrato (`docs/openapi/`), justamente para que el
| producto pueda avanzar sin romperle nada al integrador. Que ISPWatch pase a
| 2.0.0 no convierte a la API en v2.
|
| Al publicar una versión: este archivo, `CHANGELOG.md` y el tag de git se
| mueven JUNTOS. `VersionConsistencyTest` falla si los dos primeros divergen.
|
*/

return [

    'number' => '1.1.0',

    /*
    | Fecha de publicación de `number`. Es lo que la pantalla de Sistema muestra
    | como "última actualización" — antes ese campo era `new Date()`, o sea que
    | le decía al usuario que el sistema se actualizó hoy, todos los días.
    */
    'released_at' => '2026-08-20',

];
