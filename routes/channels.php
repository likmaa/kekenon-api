<?php

use App\Models\Ride;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('drivers', function ($user) {
    if (!$user || !$user->isDriver()) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'phone' => $user->phone,
    ];
});

Broadcast::channel('rider.{id}', function ($user, $id) {
    return $user && (int) $user->id === (int) $id;
});

// Canal personnel chauffeur : assignations/réassignations manuelles par le support
Broadcast::channel('driver.{id}', function ($user, $id) {
    return $user && $user->isDriver() && (int) $user->id === (int) $id;
});

Broadcast::channel('ride.{rideId}', function ($user, $rideId) {
    if (!$user) {
        return false;
    }

    $ride = Ride::find($rideId);
    if (!$ride) {
        return false;
    }

    return in_array($user->id, [$ride->rider_id, $ride->driver_id], true);
});

Broadcast::channel('admin.alerts', function ($user) {
    if (!$user) {
        return false;
    }

    return method_exists($user, 'isAdmin')
        ? ($user->isAdmin() || $user->isDeveloper())
        : false;
});

