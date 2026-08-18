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
    | Auto-servicio: el ISP emite sus propias llaves
    |--------------------------------------------------------------------------
    |
    | Camino paralelo al del operador, con guardarraíles propios. La diferencia
    | de fondo con la emisión centralizada es de riesgo, no de mecánica: cuando
    | el operador emite, un humano que conoce la plataforma decide el alcance;
    | en auto-servicio decide alguien que sólo quiere que su bot funcione, y el
    | atajo natural es pedir todos los permisos con la allowlist más ancha que
    | el formulario acepte. Por eso el límite no se sugiere: se impone abajo y
    | lo aplica TenantApiKeyController.
    |
    | Un ISP sólo puede emitir llaves de SU tenant: el tenant_id sale siempre de
    | la sesión y nunca de la petición.
    |
    */
    'self_service' => [

        /* Interruptor maestro. En false, el panel del ISP no muestra nada y los
         | endpoints responden 403: la emisión vuelve a ser sólo del operador. */
        'enabled' => (bool) env('API_KEYS_SELF_SERVICE', true),

        /*
        | Permisos que un ISP puede concederse a sí mismo. Subconjunto del
        | catálogo de arriba, NO el catálogo entero.
        |
        | `read:billing` queda fuera por defecto: expone facturas y pagos, que
        | es el dato más sensible que guarda la plataforma. No es una barrera
        | contra el ISP —son sus propios datos— sino contra que ese alcance se
        | conceda sin que nadie lo piense. Si un integrador lo necesita, el
        | operador emite esa llave por el camino centralizado, que es
        | exactamente la conversación que conviene tener.
        */
        'abilities' => [
            'read:customers',
            'read:services',
            'read:events',
            'read:support',
        ],

        /* Llaves vivas simultáneas por tenant. Una integración necesita una;
         | un puñado cubre rotación y entornos de prueba. El tope existe para
         | que una llave olvidada se note al chocar contra él. */
        'max_active_keys' => (int) env('API_KEYS_SELF_SERVICE_MAX_KEYS', 5),

        /* Consumidores de API que un ISP puede dar de alta. */
        'max_clients' => (int) env('API_KEYS_SELF_SERVICE_MAX_CLIENTS', 3),

        /*
        | Vigencia máxima, en días. En auto-servicio el vencimiento es
        | OBLIGATORIO: la opción "sin caducidad" del camino del operador es
        | justamente la que nadie revisa nunca, y una llave eterna emitida por
        | quien no administra la plataforma no la va a rotar nadie.
        */
        'max_expiration_days' => (int) env('API_KEYS_SELF_SERVICE_MAX_DAYS', 90),

        /*
        | Prefijo mínimo admitido en la allowlist de IP.
        |
        | Este es el guardarraíl que más trabaja. Sin él, el camino de menor
        | resistencia para alguien que pelea con un 403 es escribir 0.0.0.0/0,
        | y eso desarma la mejor defensa que tiene la llave: deja de importar
        | desde dónde se use. /24 admite el bloque de un servidor o una oficina
        | (256 direcciones) y rechaza cualquier cosa más ancha.
        */
        'min_ipv4_prefix' => (int) env('API_KEYS_SELF_SERVICE_MIN_IPV4', 24),
        'min_ipv6_prefix' => (int) env('API_KEYS_SELF_SERVICE_MIN_IPV6', 64),

        /*
        | A dónde avisar cuando un ISP emite una llave.
        |
        | El operador deja de ser quien autoriza, pero no puede dejar de ser
        | quien se entera: una llave nueva cambia qué datos salen de la
        | plataforma. Vacío = sólo queda el registro en el log.
        */
        'notify_email' => env('API_KEYS_SELF_SERVICE_NOTIFY_EMAIL'),
    ],

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
