<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant operador
    |--------------------------------------------------------------------------
    |
    | Sólo los administradores de este tenant pueden emitir y revocar llaves de
    | la API pública — para cualquier tenant. Es el tenant de la operación de
    | ISPWatch (el 1, "ISPWatch pruebas"), no el del ISP cliente.
    |
    | La emisión es deliberadamente centralizada: una llave define QUÉ datos
    | salen de la plataforma y HACIA DÓNDE, así que no es una preferencia que
    | cada tenant deba poder cambiarse a sí mismo sin que el operador lo sepa.
    |
    */
    'operator_tenant_id' => (int) env('API_KEYS_OPERATOR_TENANT_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Catálogo de permisos de lectura (abilities de Sanctum)
    |--------------------------------------------------------------------------
    |
    | Fuente única: valida lo que llega del panel y alimenta las casillas de la
    | pantalla de emisión. Agregar un ability aquí NO abre ningún endpoint por
    | sí solo — hay que colgarlo además del `ability:` de la ruta.
    |
    | No existe un ability comodín (`*`) a propósito: Sanctum lo interpreta como
    | "puede todo", y una llave que lo tuviera pasaría cualquier control de
    | abilities que se agregue en el futuro sin que nadie lo revise.
    |
    */
    'abilities' => [
        'read:customers' => 'Clientes (ficha, plan, estado de servicio)',
        'read:services'  => 'Servicios contratados (plan, estado y datos de red del punto)',
        'read:events'    => 'Feed de cambios comerciales (altas, cortes, cambios de plan)',
        'read:billing'   => 'Facturación (facturas y pagos)',
        'read:support'   => 'Soporte (tickets e instalaciones)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vigencia por defecto de una llave nueva, en días
    |--------------------------------------------------------------------------
    |
    | Una llave sin caducidad es una llave que nadie va a rotar nunca. El panel
    | propone este valor; el administrador puede acortarlo, y también dejarla
    | sin vencimiento de forma explícita si el integrador lo exige.
    |
    */
    'default_expiration_days' => (int) env('API_KEYS_DEFAULT_EXPIRATION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Retención de la bitácora de peticiones, en días
    |--------------------------------------------------------------------------
    */
    'log_retention_days' => (int) env('API_KEYS_LOG_RETENTION_DAYS', 90),

];
