<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\User;

class PassengerBonusService
{
    public const FIRST_RIDE_BONUS = 500;

    public function isEligible(?User $user): bool
    {
        if (! $user || ! $user->isPassenger()) {
            return false;
        }

        return ! Ride::query()
            ->where('rider_id', $user->id)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->exists();
    }

    public function availableAmount(?User $user): int
    {
        return $this->isEligible($user) ? self::FIRST_RIDE_BONUS : 0;
    }
}
