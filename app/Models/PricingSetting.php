<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    use HasFactory;

    protected $table = 'pricing_settings';

    protected $fillable = [
        'base_fare',
        'per_km',
        'per_min',
        'min_fare',
        'passenger_app_fee',
        'driver_pack_price',
        'driver_pack_rides',
        'luggage_unit_price',
        'peak_hours_enabled',
        'peak_hours_multiplier',
        'peak_hours_start_time',
        'peak_hours_end_time',
        'zones',
        'platform_commission_pct',
        'driver_commission_pct',
        'maintenance_commission_pct',
        'weather_multiplier',
        'weather_mode_enabled',
        'night_multiplier',
        'night_start_time',
        'night_end_time',
        'stop_rate_per_min',
        'pickup_grace_period_m',
        'pickup_waiting_rate_per_min',
        'out_of_city_enabled',
        'out_of_city_multiplier',
        'out_of_city_min_fare',
        'inner_city_lat',
        'inner_city_lng',
        'inner_city_radius_km',
    ];

    protected $casts = [
        'peak_hours_enabled' => 'boolean',
        'peak_hours_multiplier' => 'float',
        'zones' => 'array',
        'passenger_app_fee' => 'integer',
        'driver_pack_price' => 'integer',
        'driver_pack_rides' => 'integer',
        'platform_commission_pct' => 'integer',
        'driver_commission_pct' => 'integer',
        'maintenance_commission_pct' => 'integer',
        'weather_multiplier' => 'float',
        'weather_mode_enabled' => 'boolean',
        'night_multiplier' => 'float',
        'stop_rate_per_min' => 'integer',
        'pickup_grace_period_m' => 'integer',
        'pickup_waiting_rate_per_min' => 'integer',
        'out_of_city_enabled' => 'boolean',
        'out_of_city_multiplier' => 'float',
        'out_of_city_min_fare' => 'integer',
        'inner_city_lat' => 'float',
        'inner_city_lng' => 'float',
        'inner_city_radius_km' => 'integer',
    ];
}
