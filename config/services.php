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

    /*
    | SUNAT (spec 004). En beta los envíos usan las credenciales genéricas de
    | prueba de SUNAT (A-31); la clave SOL real de la empresa se guarda para
    | producción. {ruc} se reemplaza por el RUC de la empresa.
    */
    'sunat' => [
        'beta_wsdl' => env('SUNAT_BETA_WSDL', \Greenter\Ws\Services\SunatEndpoints::FE_BETA.'?wsdl'),
        'beta_user' => env('SUNAT_BETA_USER', '{ruc}MODDATOS'),
        'beta_password' => env('SUNAT_BETA_PASSWORD', 'moddatos'),
        'timeout' => (int) env('SUNAT_TIMEOUT', 10),
        // Envío de comprobantes: más holgado que la comprobación de conexión.
        'send_timeout' => (int) env('SUNAT_SEND_TIMEOUT', 15),
    ],

];
