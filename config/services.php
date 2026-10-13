<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        // Indicativo que se antepone a un número nacional de 10 dígitos al
        // armar un enlace wa.me (ContractSignatureLinkController::whatsappUrl).
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '57'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Converza · estado del servicio del ISP (KAN-126)
    |--------------------------------------------------------------------------
    |
    | Converza (Core Brain) le cobra el SaaS a cada ISP y, si una factura vence,
    | publica la suspensión en GET {url}/saas/estado/{tenant_id}. ISPWatch la
    | consulta y le cierra el PANEL al equipo del ISP. Ver
    | App\Services\Saas\ConverzaServiceStatus.
    |
    | Con `url` o `saas_status_token` vacíos la comprobación no corre: nadie se
    | corta. El token es el MISMO valor que BRAIN_SAAS_STATUS_TOKEN en Converza.
    |
    */
    'converza' => [
        'url' => env('CONVERZA_URL'),
        'saas_status_token' => env('CONVERZA_SAAS_STATUS_TOKEN'),

        // Corre en CADA petición del panel: un Converza lento no puede volver
        // lento a ISPWatch. Pasado el plazo se deja pasar (falla abierto).
        'saas_status_timeout' => (float) env('CONVERZA_SAAS_STATUS_TIMEOUT', 3),

        // Store propio y local a propósito: el de la app es `database`, y leer
        // el estado de ahí sumaría un viaje a Postgres a cada petición.
        'saas_status_cache_store' => env('CONVERZA_SAAS_STATUS_CACHE_STORE', 'file'),
    ],

];
