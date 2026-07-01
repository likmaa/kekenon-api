<?php

$user = App\Models\User::create([
    'name' => 'Chauffeur Testeur',
    'email' => 'testdriver@kekenon.local',
    'phone' => '+22999999999',
    'password' => bcrypt('password'),
    'role' => 'driver',
    'is_active' => true,
    'phone_verified_at' => now(),
]);

App\Models\DriverProfile::create([
    'user_id' => $user->id,
    'status' => 'pending',
    'vehicle_number' => 'AB-123-CD',
    'license_number' => 'LIC-987654321',
    'vehicle_make' => 'Toyota',
    'vehicle_model' => 'Corolla',
    'vehicle_year' => '2015',
    'vehicle_color' => 'Jaune',
    'license_plate' => 'BJ-2025-XX',
    'vehicle_type' => 'sedan',
    'documents' => [
        'id_card' => [
            'name' => 'Carte d\'identité',
            'status' => 'pending',
            'path' => 'https://placehold.co/600x400/eeeeee/4b5563?text=ID+Card', 
        ],
        'driver_license' => [
            'name' => 'Permis de conduire',
            'status' => 'pending',
            'path' => 'https://placehold.co/600x400/eeeeee/4b5563?text=Permis',
        ],
        'vehicle_photo' => [
            'name' => 'Photo Véhicule',
            'status' => 'pending',
            'path' => 'https://placehold.co/600x400/eeeeee/4b5563?text=Taxi',
        ]
    ]
]);

$user2 = App\Models\User::create([
    'name' => 'Jean Conducteur',
    'email' => 'jean@kekenon.local',
    'phone' => '+22988888888',
    'password' => bcrypt('password'),
    'role' => 'driver',
    'is_active' => true,
    'phone_verified_at' => now(),
]);

App\Models\DriverProfile::create([
    'user_id' => $user2->id,
    'status' => 'pending',
    'vehicle_number' => 'XZ-999-YY',
    'license_number' => 'LIC-111222333',
    'vehicle_make' => 'Honda',
    'vehicle_model' => 'Civic',
    'vehicle_year' => '2018',
    'vehicle_color' => 'Gris',
    'license_plate' => 'BJ-2026-ZZ',
    'vehicle_type' => 'sedan',
    'documents' => [
        'id_card' => [
            'name' => 'Carte d\'identité',
            'status' => 'pending',
            'path' => 'https://placehold.co/600x400/f3f4f6/111827?text=ID+Card+Jean', 
        ]
    ]
]);

echo "Fake drivers created successfully!\n";
