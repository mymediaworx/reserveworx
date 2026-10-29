<?php

use App\Domain\Payments\ConnectAccountSpec;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Pinned in code. Accounts v2 onboarding uses this preview version.
        'api_version' => ConnectAccountSpec::API_VERSION,
    ],

    'ghl' => [
        'client_id' => env('GHL_CLIENT_ID'),
        'client_secret' => env('GHL_CLIENT_SECRET'),
        'redirect' => env('GHL_REDIRECT_URI'),
        'base_url' => env('GHL_BASE_URL', 'https://services.leadconnectorhq.com'),
        'version' => env('GHL_VERSION', '2021-07-28'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
