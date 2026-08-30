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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL') . env('GOOGLE_REDIRECT_PATH'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'tourkokan-658d1'),
        'server_key' => env('FIREBASE_SERVER_KEY'),
        'fcm_url'    => env('FIREBASE_FCM_URL', 'https://fcm.googleapis.com/fcm/send'),
    ],

    'msg91' => [
        'auth_key'  => env('MSG91_AUTH_KEY'),
        'route'     => env('MSG91_ROUTE'),
        'sender_id' => env('MSG91_SENDER_ID'),
    ],
];
