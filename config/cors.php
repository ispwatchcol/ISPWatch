<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Restrict cross-origin access to THIS application's own frontend only.
    | The SPA and API are served from the same origin in dev and prod, so the
    | app's own requests aren't cross-origin; this allowlist exists to block
    | every OTHER origin from calling the API with the user's session cookie.
    |
    | Origins come from CORS_ALLOWED_ORIGINS (comma-separated), falling back to
    | FRONTEND_URL and then APP_URL. We never use '*': credentialed (cookie)
    | requests require an explicit origin allowlist, and '*' would let any site
    | drive the authenticated API.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            env('FRONTEND_URL', env('APP_URL', 'http://localhost:8000'))
        ))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Sin esto el navegador oculta el header a JS en cualquier llamada
    // cross-origin (el servidor de Vite en :5173 contra la API en :8000),
    // así que el aviso de "un bloque no se pudo insertar" de la vista previa
    // de plantillas (DocumentTemplateController::preview) nunca se dispara.
    // En producción front y API comparten origen y el header ya se ve, pero
    // una función que solo funciona en un despliegue no es una función.
    'exposed_headers' => ['X-Template-Warnings'],

    'max_age' => 0,

    // Required for Sanctum's cookie-based SPA authentication.
    'supports_credentials' => true,

];
