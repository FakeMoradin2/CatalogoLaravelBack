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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'stripe' => [
        // Clave pública (pk_...): compatible con STRIPE_KEY o STRIPE_PUBLISHABLE_KEY (otros proyectos).
        'key' => env('STRIPE_KEY', env('STRIPE_PUBLISHABLE_KEY')),
        // Secreto (sk_...): compatible con STRIPE_SECRET o STRIPE_SECRET_KEY (otros proyectos / docs Stripe).
        'secret' => env('STRIPE_SECRET', env('STRIPE_SECRET_KEY')),
        'currency' => strtolower((string) env('STRIPE_CURRENCY', 'usd')),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'checkout_allow_promotion_codes' => env('STRIPE_CHECKOUT_ALLOW_PROMOTION_CODES', true),
        'checkout_locale' => env('STRIPE_CHECKOUT_LOCALE', 'es'),
    ],

];
