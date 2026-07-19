<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\PricingSetting;
use App\Services\EconomicModelService;

class PricingController extends Controller
{
    protected string $cacheKey = 'pricing.config';

    public function __construct(private EconomicModelService $economicModel)
    {
    }

    public function get()
    {
        $setting = PricingSetting::query()->first();

        // Si aucune configuration n'existe encore, on en crée une avec des valeurs par défaut
        if (!$setting) {
            $setting = PricingSetting::create([
                'base_fare' => 700,
                'per_km' => 200,
                'min_fare' => 1000,
                'passenger_app_fee' => EconomicModelService::DEFAULT_PASSENGER_APP_FEE,
                'driver_pack_price' => EconomicModelService::DEFAULT_DRIVER_PACK_PRICE,
                'driver_pack_rides' => EconomicModelService::DEFAULT_DRIVER_PACK_RIDES,
                'zones' => [],
                'peak_hours_enabled' => false,
                'peak_hours_multiplier' => 1.0,
                'peak_hours_start_time' => '17:00:00',
                'peak_hours_end_time' => '20:00:00',
                'platform_commission_pct' => 0,
                'driver_commission_pct' => 100,
                'maintenance_commission_pct' => 0,
                'weather_multiplier' => 1.0,
                'weather_mode_enabled' => false,
                'night_multiplier' => 1.0,
                'night_start_time' => '22:00:00',
                'night_end_time' => '06:00:00',
                'stop_rate_per_min' => 5,
            ]);
        }

        $config = [
            'base_fare' => (int) $setting->base_fare,
            'per_km' => (int) $setting->per_km,
            'per_min' => (int) ($setting->per_min ?? 5),
            'min_fare' => (int) $setting->min_fare,
            'stop_rate_per_min' => (int) ($setting->stop_rate_per_min ?? 5),
            'pickup_grace_period_m' => (int) ($setting->pickup_grace_period_m ?? 5),
            'pickup_waiting_rate_per_min' => (int) ($setting->pickup_waiting_rate_per_min ?? 10),
            'business_model' => $this->economicModel->get(),
            'delivery' => app(\App\Services\DeliveryPricingService::class)->config($setting),
            'zones' => $setting->zones ?? [],
            'peak_hours' => [
                'enabled' => (bool) $setting->peak_hours_enabled,
                'multiplier' => (float) $setting->peak_hours_multiplier,
                'start_time' => substr((string) $setting->peak_hours_start_time, 0, 5),
                'end_time' => substr((string) $setting->peak_hours_end_time, 0, 5),
            ],
            'weather' => [
                'enabled' => (bool) ($setting->weather_mode_enabled ?? false),
                'multiplier' => (float) ($setting->weather_multiplier ?? 1.0),
            ],
            'night' => [
                'multiplier' => (float) ($setting->night_multiplier ?? 1.0),
                'start_time' => substr((string) ($setting->night_start_time ?? '22:00'), 0, 5),
                'end_time' => substr((string) ($setting->night_end_time ?? '06:00'), 0, 5),
            ],
            // Compatibilité avec les anciennes versions du panel. Le moteur réel
            // n'applique plus de commission proportionnelle sur le prix de la course.
            'commission' => [
                'enabled' => false,
                'platform_pct' => 0,
                'driver_pct' => 100,
                'maintenance_pct' => 0,
            ],
            'out_of_city' => [
                'enabled' => (bool) ($setting->out_of_city_enabled ?? false),
                'multiplier' => (float) ($setting->out_of_city_multiplier ?? 1.5),
                'min_fare' => (int) ($setting->out_of_city_min_fare ?? 1500),
                'inner_city_lat' => (float) ($setting->inner_city_lat ?? 6.4969),
                'inner_city_lng' => (float) ($setting->inner_city_lng ?? 2.6289),
                'inner_city_radius_km' => (int) ($setting->inner_city_radius_km ?? 15),
            ],
        ];

        return response()->json($config);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'base_fare' => ['sometimes', 'numeric', 'min:0'],
            'per_km' => ['sometimes', 'numeric', 'min:0'],
            'per_min' => ['sometimes', 'integer', 'min:0'],
            'min_fare' => ['sometimes', 'numeric', 'min:0'],
            'stop_rate_per_min' => ['sometimes', 'integer', 'min:0'],
            'pickup_grace_period_m' => ['sometimes', 'integer', 'min:0'],
            'pickup_waiting_rate_per_min' => ['sometimes', 'integer', 'min:0'],
            'business_model' => ['sometimes', 'array'],
            'business_model.passenger_app_fee' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'business_model.driver_pack_price' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'business_model.driver_pack_rides' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'delivery' => ['sometimes', 'array'],
            'delivery.small_fee' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'delivery.medium_fee' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'delivery.large_fee' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'delivery.fragile_fee' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'delivery.weight_threshold_kg' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'delivery.extra_kg_fee' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'zones' => ['sometimes', 'array'],
            'peak_hours' => ['sometimes', 'array'],
            'peak_hours.enabled' => ['sometimes', 'boolean'],
            'peak_hours.multiplier' => ['sometimes', 'numeric', 'min:0'],
            'peak_hours.start_time' => ['sometimes', 'string'],
            'peak_hours.end_time' => ['sometimes', 'string'],
            'weather' => ['sometimes', 'array'],
            'weather.enabled' => ['sometimes', 'boolean'],
            'weather.multiplier' => ['sometimes', 'numeric', 'min:0'],
            'night' => ['sometimes', 'array'],
            'night.multiplier' => ['sometimes', 'numeric', 'min:0'],
            'night.start_time' => ['sometimes', 'string'],
            'night.end_time' => ['sometimes', 'string'],
            'commission' => ['sometimes', 'array'],
            'commission.platform_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'commission.driver_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'commission.maintenance_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'out_of_city' => ['sometimes', 'array'],
            'out_of_city.enabled' => ['sometimes', 'boolean'],
            'out_of_city.multiplier' => ['sometimes', 'numeric', 'min:0'],
            'out_of_city.min_fare' => ['sometimes', 'integer', 'min:0'],
            'out_of_city.inner_city_lat' => ['sometimes', 'numeric'],
            'out_of_city.inner_city_lng' => ['sometimes', 'numeric'],
            'out_of_city.inner_city_radius_km' => ['sometimes', 'integer', 'min:1'],
        ]);

        $setting = PricingSetting::query()->first() ?? new PricingSetting();

        if (array_key_exists('base_fare', $data)) {
            $setting->base_fare = (int) $data['base_fare'];
        }
        if (array_key_exists('per_km', $data)) {
            $setting->per_km = (int) $data['per_km'];
        }
        if (array_key_exists('per_min', $data)) {
            $setting->per_min = (int) $data['per_min'];
        }
        if (array_key_exists('min_fare', $data)) {
            $setting->min_fare = (int) $data['min_fare'];
        }
        if (array_key_exists('stop_rate_per_min', $data)) {
            $setting->stop_rate_per_min = (int) $data['stop_rate_per_min'];
        }
        if (array_key_exists('pickup_grace_period_m', $data)) {
            $setting->pickup_grace_period_m = (int) $data['pickup_grace_period_m'];
        }
        if (array_key_exists('pickup_waiting_rate_per_min', $data)) {
            $setting->pickup_waiting_rate_per_min = (int) $data['pickup_waiting_rate_per_min'];
        }
        if (array_key_exists('business_model', $data)) {
            $businessModel = $data['business_model'];
            if (array_key_exists('passenger_app_fee', $businessModel)) {
                $setting->passenger_app_fee = (int) $businessModel['passenger_app_fee'];
            }
            if (array_key_exists('driver_pack_price', $businessModel)) {
                $setting->driver_pack_price = (int) $businessModel['driver_pack_price'];
            }
            if (array_key_exists('driver_pack_rides', $businessModel)) {
                $setting->driver_pack_rides = (int) $businessModel['driver_pack_rides'];
            }
        }
        if (array_key_exists('delivery', $data)) {
            $delivery = $data['delivery'];
            foreach ([
                'small_fee' => 'delivery_small_fee',
                'medium_fee' => 'delivery_medium_fee',
                'large_fee' => 'delivery_large_fee',
                'fragile_fee' => 'delivery_fragile_fee',
                'weight_threshold_kg' => 'delivery_weight_threshold_kg',
                'extra_kg_fee' => 'delivery_extra_kg_fee',
            ] as $input => $column) {
                if (array_key_exists($input, $delivery)) {
                    $setting->{$column} = (int) $delivery[$input];
                }
            }
        }
        if (array_key_exists('zones', $data)) {
            $setting->zones = $data['zones'];
        }

        if (array_key_exists('peak_hours', $data)) {
            $ph = $data['peak_hours'];
            if (array_key_exists('enabled', $ph))
                $setting->peak_hours_enabled = (bool) $ph['enabled'];
            if (array_key_exists('multiplier', $ph))
                $setting->peak_hours_multiplier = (float) $ph['multiplier'];
            if (array_key_exists('start_time', $ph))
                $setting->peak_hours_start_time = $ph['start_time'] . ':00';
            if (array_key_exists('end_time', $ph))
                $setting->peak_hours_end_time = $ph['end_time'] . ':00';
        }

        if (array_key_exists('weather', $data)) {
            $w = $data['weather'];
            if (array_key_exists('enabled', $w))
                $setting->weather_mode_enabled = (bool) $w['enabled'];
            if (array_key_exists('multiplier', $w))
                $setting->weather_multiplier = (float) $w['multiplier'];
        }

        if (array_key_exists('night', $data)) {
            $n = $data['night'];
            if (array_key_exists('multiplier', $n))
                $setting->night_multiplier = (float) $n['multiplier'];
            if (array_key_exists('start_time', $n))
                $setting->night_start_time = $n['start_time'] . ':00';
            if (array_key_exists('end_time', $n))
                $setting->night_end_time = $n['end_time'] . ':00';
        }

        // Modèle actuel : le zem conserve 100 % du prix de la course.
        $setting->platform_commission_pct = 0;
        $setting->driver_commission_pct = 100;
        $setting->maintenance_commission_pct = 0;

        if (array_key_exists('out_of_city', $data)) {
            $oc = $data['out_of_city'];
            if (array_key_exists('enabled', $oc))
                $setting->out_of_city_enabled = (bool) $oc['enabled'];
            if (array_key_exists('multiplier', $oc))
                $setting->out_of_city_multiplier = (float) $oc['multiplier'];
            if (array_key_exists('min_fare', $oc))
                $setting->out_of_city_min_fare = (int) $oc['min_fare'];
            if (array_key_exists('inner_city_lat', $oc))
                $setting->inner_city_lat = (float) $oc['inner_city_lat'];
            if (array_key_exists('inner_city_lng', $oc))
                $setting->inner_city_lng = (float) $oc['inner_city_lng'];
            if (array_key_exists('inner_city_radius_km', $oc))
                $setting->inner_city_radius_km = (int) $oc['inner_city_radius_km'];
        }

        $setting->save();

        Cache::forget('pricing.config');
        Cache::forget('pricing.commission');

        return $this->get();
    }
}
