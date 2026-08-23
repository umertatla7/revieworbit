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

    'square' => [
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'api_version' => env('SQUARE_API_VERSION', '2026-07-15'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'application_secret' => env('SQUARE_APPLICATION_SECRET'),
        'redirect_uri' => env('SQUARE_REDIRECT_URI'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'web_url' => env('WEB_URL', 'http://localhost:3000'),
        'history_days' => env('SQUARE_HISTORY_DAYS', 365),
        'upcoming_days' => env('SQUARE_UPCOMING_DAYS', 365),
    ],

    'twilio' => [
        'provider' => env('MESSAGING_PROVIDER', 'fake'),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL', env('APP_URL').'/api/v1/webhooks/twilio/status'),
        'inbound_webhook_url' => env('TWILIO_INBOUND_WEBHOOK_URL', env('APP_URL').'/api/v1/webhooks/twilio/inbound'),
        'tracking_base_url' => env('TRACKING_BASE_URL', env('APP_URL')),
    ],

    'frontend' => [
        'url' => env('WEB_URL', 'http://localhost:3000'),
    ],

];
