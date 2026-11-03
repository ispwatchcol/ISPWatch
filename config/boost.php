<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Boost Master Switch
    |--------------------------------------------------------------------------
    |
    | This option may be used to disable all Boost functionality which will
    | prevent Boost's routes from being registered and will also disable
    | Boost's browser logging functionality from reading or operating.
    |
    */

    'enabled' => env('BOOST_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Boost Project Rules
    |--------------------------------------------------------------------------
    |
    | Project rules let agents write decisions, traps and standing constraints
    | as tracked Markdown in /.ai/rules/. Enabling "scoped_guidelines" also
    | moves path-scoped guidelines to .ai/rules/boost/ - it stays opt-in.
    |
    */

    'rules' => [
        'enabled' => env('BOOST_RULES_ENABLED', true),
        'scoped_guidelines' => env('BOOST_RULES_SCOPED_GUIDELINES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Guidelines
    |--------------------------------------------------------------------------
    |
    | Any guidelines listed here will be excluded whenever Boost composes your
    | AI guidelines during boost:install or boost:update. Entries match the
    | names shown within the boost:install summary, e.g. "livewire/core".
    |
    */

    // ISPWatch: el frontend es una SPA Vue (no Livewire/Volt), el despliegue es DigitalOcean
    // App Platform (no Laravel Cloud), en local se sirve con `php artisan serve` (no Herd para
    // todo el equipo) y el código NO sigue el formato de Pint: la guía de Pint obligaría a
    // reformatear archivos enteros en cada PR.
    'guidelines' => [
        'exclude' => [
            'livewire/core',
            'volt/core',
            'deployments',
            'herd',
            'pint/core',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Skills
    |--------------------------------------------------------------------------
    |
    | Any skills listed here will not be installed or synced to your agents
    | by boost:install and boost:update, e.g. "fluxui-development". Your
    | own skills within the ".ai/skills" directory are never excluded.
    |
    */

    'skills' => [
        'exclude' => [
            'livewire-development',
            'volt-development',
            'deploying-to-cloud',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Herramientas MCP (ISPWatch)
    |--------------------------------------------------------------------------
    |
    | El .env local apunta a Supabase, la misma instancia que producción (el
    | schema `public`); sólo DB_SCHEMA=ispwatch_dev separa ambos mundos.
    |
    | - Tinker ejecuta PHP arbitrario: queda APAGADO (es el valor por defecto
    |   de Boost; se fija aquí para que nadie lo encienda sin leer esto).
    | - DatabaseQuery es de solo lectura, pero un `SELECT ... FROM public.x`
    |   traería datos reales de clientes al contexto del agente: excluida.
    |   El esquema (DatabaseSchema) sí queda: es estructura, no datos.
    |
    */

    'tinker_tool_enabled' => false,

    'mcp' => [
        'tools' => [
            'exclude' => [
                \Laravel\Boost\Mcp\Tools\DatabaseQuery::class,
                \Laravel\Boost\Mcp\Tools\Tinker::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Boost Executables Paths
    |--------------------------------------------------------------------------
    |
    | These options allow you to specify custom paths for the executables that
    | Boost uses. While configured, they take precedence over the automatic
    | discovery mechanism. When undefined, your system defaults are used.
    |
    */

    'executable_paths' => [
        'php' => env('BOOST_PHP_EXECUTABLE_PATH'),
        'composer' => env('BOOST_COMPOSER_EXECUTABLE_PATH'),
        'npm' => env('BOOST_NPM_EXECUTABLE_PATH'),
        'vendor_bin' => env('BOOST_VENDOR_BIN_EXECUTABLE_PATH'),
        'current_directory' => env('BOOST_CURRENT_DIRECTORY_EXECUTABLE_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Boost Browser Logs Watcher
    |--------------------------------------------------------------------------
    |
    | The following option may be used to enable or disable the browser logs
    | watcher feature within Laravel Boost. The log watcher will read any
    | errors within the browser's console to give Boost better context.
    |
    */

    'browser_logs_watcher' => env('BOOST_BROWSER_LOGS_WATCHER', true),

    /*
    |--------------------------------------------------------------------------
    | Browser Log Levels
    |--------------------------------------------------------------------------
    |
    | This option defines which browser console log levels will be captured by
    | Boost's browser logger. You may trim this list down to ['error'] when
    | warnings, info, and debug messages become too noisy to be relevant.
    |
    */

    'browser_log_levels' => explode(',', env('BOOST_BROWSER_LOG_LEVELS', 'error,warning,info,debug')),

];
