<?php

namespace App\Services;

use App\Models\PricingSetting;

/** Calcul unique des suppléments de livraison, partagé par l'estimation et la clôture. */
class DeliveryPricingService
{
    /** @return array<string, int> */
    public function config(?PricingSetting $setting = null): array
    {
        $setting ??= PricingSetting::query()->first();

        return [
            'small_fee' => (int) ($setting?->delivery_small_fee ?? 0),
            'medium_fee' => (int) ($setting?->delivery_medium_fee ?? 200),
            'large_fee' => (int) ($setting?->delivery_large_fee ?? 500),
            'fragile_fee' => (int) ($setting?->delivery_fragile_fee ?? 200),
            'weight_threshold_kg' => max(0, (int) ($setting?->delivery_weight_threshold_kg ?? 5)),
            'extra_kg_fee' => (int) ($setting?->delivery_extra_kg_fee ?? 100),
        ];
    }

    /** @return array{size_fee:int, fragile_fee:int, weight_fee:int, total:int} */
    public function breakdown(?string $size, float|int|string|null $weightKg, bool $fragile, ?PricingSetting $setting = null): array
    {
        $config = $this->config($setting);
        $normalizedSize = in_array($size, ['small', 'medium', 'large'], true) ? $size : 'medium';
        $sizeFee = $config[$normalizedSize.'_fee'];
        $fragileFee = $fragile ? $config['fragile_fee'] : 0;
        $weight = max(0, (float) ($weightKg ?: 0));
        $extraKg = max(0, (int) ceil($weight - $config['weight_threshold_kg']));
        $weightFee = $extraKg * $config['extra_kg_fee'];

        return [
            'size_fee' => $sizeFee,
            'fragile_fee' => $fragileFee,
            'weight_fee' => $weightFee,
            'total' => $sizeFee + $fragileFee + $weightFee,
        ];
    }
}
