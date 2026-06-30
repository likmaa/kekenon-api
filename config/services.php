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

    'geniuspay' => [
        /** Clé publique marchand (pk_sandbox_… / pk_live_…). */
        'public_key' => env('GENIUSPAY_PUBLIC_KEY'),
        /** Clé secrète marchand (sk_sandbox_… / sk_live_…). */
        'secret_key' => env('GENIUSPAY_SECRET_KEY'),
        /** @deprecated Utiliser public_key + secret_key. Conservé pour migration (pk_ ou sk_ seul). */
        'api_key' => env('GENIUSPAY_API_KEY'),
        'webhook_secret' => env('GENIUSPAY_WEBHOOK_SECRET'),
        'sandbox' => env('GENIUSPAY_SANDBOX', true),
    ],

    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
    ],

    'kyasms' => [
        'api_key' => env('KYASMS_API_KEY'),
        'base_url' => env('KYASMS_BASE_URL', 'https://route.kyasms.com/api/v3'),
        'app_id' => env('KYASMS_APP_ID'),
        'from' => env('KYASMS_FROM'),
    ],

    'fcm' => [
        'key' => env('FCM_SERVER_KEY'),
        /** JSON Firebase Admin (FCM HTTP v1) : chaine brute OU base64 du fichier — jamais commite. */
        'service_account_json' => env('FIREBASE_SERVICE_ACCOUNT_JSON'),
        /** Chemin absolu lisible par PHP (ex. secret Docker /run/secrets/...). Alternative au JSON en .env. */
        'service_account_path' => env('FIREBASE_SERVICE_ACCOUNT_PATH'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'voice_model' => env('GEMINI_VOICE_MODEL', 'gemini-2.5-flash'),
        'geo_model' => env('GEMINI_GEO_MODEL', 'gemini-2.5-flash'),
    ],

    /**
     * Connexion revue Apple / Google Play : pas de SMS réel, OTP fixe.
     * Le numéro est normalisé comme les autres (Phone::normalize), ex. +22901XXXXXXXX.
     */
    'review_login' => [
        'phone' => env('REVIEW_LOGIN_PHONE', '+229000000'),
        'otp' => env('REVIEW_LOGIN_OTP', '123456'),
        'otp_key' => env('REVIEW_LOGIN_OTP_KEY', 'google-test-key'),
    ],

];
