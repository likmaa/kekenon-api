<?php

/**
 * Contrôle de version des apps mobiles (FEAT-11).
 * Les URLs stores peuvent être surchargées via .env ; sinon le Play Store est dérivé du package Android.
 */
return [
    'apps' => [
        'passenger' => [
            'min_version' => env('MOBILE_PASSENGER_MIN_VERSION', '1.0.0'),
            'latest_version' => env('MOBILE_PASSENGER_LATEST_VERSION', '1.1.0'),
            'android_package' => env('MOBILE_PASSENGER_ANDROID_PACKAGE', 'com.kekenon.passenger'),
            'android_store_url' => env('MOBILE_PASSENGER_ANDROID_URL', ''),
            'ios_store_url' => env('MOBILE_PASSENGER_IOS_URL', ''),
        ],
        'driver' => [
            'min_version' => env('MOBILE_DRIVER_MIN_VERSION', '1.0.0'),
            'latest_version' => env('MOBILE_DRIVER_LATEST_VERSION', '1.1.0'),
            'android_package' => env('MOBILE_DRIVER_ANDROID_PACKAGE', 'com.kekenon.driver'),
            'android_store_url' => env('MOBILE_DRIVER_ANDROID_URL', ''),
            'ios_store_url' => env('MOBILE_DRIVER_IOS_URL', ''),
        ],
    ],
];
