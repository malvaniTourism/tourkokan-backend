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

        /*
         * OAuth client ids a Google id_token may be issued for ("aud").
         * googleAuth trusts Google's signature check but must also confirm the
         * token was minted for one of OUR clients — otherwise an id_token from
         * any unrelated app would log its owner in here.
         *
         * Comma separated, and set per environment in .env (see env.example):
         * the website, Android and iOS each sign in through a different OAuth
         * client, so every one still in use must be listed. An Android client
         * authorises by package name and certificate fingerprint and has no
         * JavaScript origins, which is why the browser needs a client of its own
         * rather than reusing the app's.
         *
         * Empty is not a safe default, so there is none: googleAuth refuses
         * outright and logs when this is unset, rather than accepting a token
         * minted for anyone.
         */
        'allowed_audiences' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('GOOGLE_ALLOWED_AUDIENCES', '')
        )))),
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

    /*
     | Trip planner. The driver defaults to `fake` so the pipeline runs in CI
     | and on a fresh checkout with no key and no spend; `ai_driver` in
     | ai_configs overrides this at runtime, so an admin can switch without a
     | deploy. The key is read from the environment only — it must never reach
     | the mobile app or the admin panel.
     */
    'ai' => [
        'driver' => env('AI_DRIVER', 'fake'),
        'key'    => env('ANTHROPIC_API_KEY'),
    ],
];
