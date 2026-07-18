<?php

namespace App\Services;

use App\Models\PricingSetting;

/** Source de vérité du modèle économique Kêkênon. */
class EconomicModelService
{
    public const DEFAULT_PASSENGER_APP_FEE = 25;
    public const DEFAULT_DRIVER_PACK_PRICE = 500;
    public const DEFAULT_DRIVER_PACK_RIDES = 10;

    /**
     * @return array{
     *   driver_ride_share_pct:int,
     *   passenger_app_fee:int,
     *   driver_pack_price:int,
     *   driver_pack_rides:int,
     *   driver_effective_fee_per_ride:float,
     *   expected_platform_revenue_per_ride:float
     * }
     */
    public function get(): array
    {
        $setting = PricingSetting::query()->first();
        $passengerFee = max(0, (int) ($setting?->passenger_app_fee ?? self::DEFAULT_PASSENGER_APP_FEE));
        $packPrice = max(0, (int) ($setting?->driver_pack_price ?? self::DEFAULT_DRIVER_PACK_PRICE));
        $packRides = max(1, (int) ($setting?->driver_pack_rides ?? self::DEFAULT_DRIVER_PACK_RIDES));
        $driverFeePerRide = $packPrice / $packRides;

        return [
            'driver_ride_share_pct' => 100,
            'passenger_app_fee' => $passengerFee,
            'driver_pack_price' => $packPrice,
            'driver_pack_rides' => $packRides,
            'driver_effective_fee_per_ride' => round($driverFeePerRide, 2),
            'expected_platform_revenue_per_ride' => round($passengerFee + $driverFeePerRide, 2),
        ];
    }
}
