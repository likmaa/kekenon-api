<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rides:expire', function () {
    $expiredRides = \App\Models\Ride::where('status', 'requested')
        ->where('created_at', '<', now()->subMinutes(10))
        ->get();

    /** @var \App\Models\Ride $ride */
    foreach ($expiredRides as $ride) {
        $ride->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'timeout_no_driver'
        ]);

        rescue(fn () => broadcast(new \App\Events\RideCancelled($ride, 'system')));
        $this->info("Expired ride ID: {$ride->id}");
    }
})->purpose('Expire ride requests in "requested" older than 10 minutes (timeout_no_driver)');

Artisan::command('rides:simulate-arrival', function () {
    /** @var \App\Models\Ride|null $ride */
    $ride = \App\Models\Ride::where('status', 'accepted')->latest()->first();

    if (! $ride) {
        $this->warn('Aucune course au statut "accepted" trouvée.');

        return 1;
    }

    $ride->update([
        'status' => 'arrived',
        'arrived_at' => now(),
    ]);

    rescue(fn () => broadcast(new \App\Events\RideArrived(
        $ride->id,
        $ride->rider_id,
        $ride->arrived_at->toIso8601String()
    )));

    $this->info("Course #{$ride->id} marquée comme « arrived » et diffusée (dev).");

    return 0;
})->purpose('DEV — simule l’arrivée du chauffeur sur la dernière course "accepted"');

Artisan::command('drivers:expire-stale', function () {
    $threshold = now()->subMinutes(45);

    $staleDrivers = \App\Models\User::where('role', 'driver')
        ->where('is_online', true)
        ->where(function ($query) use ($threshold) {
            $query->whereNull('last_location_at')
                ->orWhere('last_location_at', '<', $threshold);
        })
        ->get();

    $count = $staleDrivers->count();

    /** @var \App\Models\User $driver */
    foreach ($staleDrivers as $driver) {
        $driver->update(['is_online' => false]);
        $this->info("Mise hors ligne du chauffeur #{$driver->id} ({$driver->name}) — dernière activité : " . ($driver->last_location_at ?? 'jamais'));
    }

    $this->info("{$count} chauffeur(s) mis hors ligne.");
})->purpose('Met hors ligne les chauffeurs sans activité GPS depuis 45 minutes');

Artisan::command('fcm:ping', function () {
    $fcm = app(\App\Services\FcmService::class);

    $path = config('services.fcm.service_account_path');
    $jsonEnv = config('services.fcm.service_account_json');
    $jsonLen = is_string($jsonEnv) ? strlen(trim($jsonEnv)) : 0;

    $this->line('FCM — lecture config :');
    $this->line('  project_id chargé : ' . ($fcm->getLoadedProjectId() ?? '(aucun)'));
    $this->line('  FIREBASE_SERVICE_ACCOUNT_PATH : ' . (is_string($path) && $path !== '' ? $path : '(vide)'));
    $this->line('  FIREBASE_SERVICE_ACCOUNT_JSON : ' . ($jsonLen > 0 ? "défini ({$jsonLen} car.) — peut écraser le fichier si invalide" : 'vide'));

    if ($fcm->canAuthenticate()) {
        $this->info('FCM : configuration OK (jeton OAuth Google obtenu).');

        return 0;
    }

    $this->error('FCM : impossible d’obtenir un jeton OAuth.');

    $fail = $fcm->getLastFcmTokenFailure();
    if (is_array($fail)) {
        $this->newLine();
        $this->warn('Diagnostic [' . $fail['step'] . '] :');
        $this->line($fail['detail']);
        $this->newLine();
    }

    $this->line('1) Firebase Console → projet kekenon → Engrenage « Paramètres du projet » → Comptes de service.');
    $this->line('2) « Générer une nouvelle clé privée » (JSON) → enregistrer sous :');
    $this->line('   backend/storage/app/firebase-service-account.json');
    $this->line('3) Dans backend/.env : FIREBASE_SERVICE_ACCOUNT_PATH=storage/app/firebase-service-account.json');
    $this->line('   (ou FIREBASE_SERVICE_ACCOUNT_JSON = sortie de : openssl base64 -A -in ce-fichier.json)');
    $this->line('4) Image Docker : rebuild après git pull si composer.json a changé (phpseclib).');

    return 1;
})->purpose('Vérifie la configuration FCM (Firebase Admin HTTP v1)');
