<?php

namespace App\Services;

use App\Events\RideCompleted;
use App\Models\PricingSetting;
use App\Models\Ride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RideCompletionService
 *
 * Logique unique de complétion d'une course (tarif, commissions, mouvements
 * de wallet, broadcast, FCM). Partagée entre :
 *   - TripsController::complete  → complétion normale par le chauffeur
 *   - Admin\RidesController::complete → complétion manuelle (récupération de crash)
 *
 * Centraliser ici évite toute divergence de calcul de tarif/commission entre
 * les deux chemins (risque financier).
 */
class RideCompletionService
{
    public function __construct(
        private FcmService $fcm,
        private EconomicModelService $economicModel,
    )
    {
    }

    /**
     * Complète une course et retourne ['ride' => Ride, 'driverAmount' => int].
     * Le chauffeur crédité est celui assigné à la course ($ride->driver).
     *
     * @param  int|null  $reportedDistanceM  Distance rapportée par l'app chauffeur (optionnelle).
     */
    public function complete(Ride $ride, ?int $reportedDistanceM = null): array
    {
        $driver = $ride->driver;
        if (!$driver) {
            throw new \RuntimeException('Cannot complete a ride without an assigned driver.');
        }

        $result = DB::transaction(function () use ($ride, $driver, $reportedDistanceM) {
            // Clôturer un arrêt actif non terminé
            if ($ride->stop_started_at) {
                $duration = (int) now()->diffInSeconds($ride->stop_started_at, true);
                $ride->total_stop_duration_s += $duration;
                $ride->stop_started_at = null;
            }

            $pricing = Cache::remember('pricing.config', 60, function () {
                $s = PricingSetting::query()->first();
                return [
                    'base_fare' => (int) ($s?->base_fare ?? 700),
                    'per_km' => (int) ($s?->per_km ?? 200),
                    'per_min' => (int) ($s?->per_min ?? 5),
                    'min_fare' => (int) ($s?->min_fare ?? 1000),
                    'luggage_unit_price' => (int) ($s?->luggage_unit_price ?? 500),
                    'stop_rate_per_min' => (int) ($s?->stop_rate_per_min ?? 5),
                    'weather' => [
                        'enabled' => (bool) ($s?->weather_mode_enabled ?? false),
                        'multiplier' => (float) ($s?->weather_multiplier ?? 1.0),
                    ],
                    'night' => [
                        'multiplier' => (float) ($s?->night_multiplier ?? 1.0),
                        'start_time' => substr((string) ($s?->night_start_time ?? '22:00'), 0, 5),
                        'end_time' => substr((string) ($s?->night_end_time ?? '06:00'), 0, 5),
                    ],
                    'peak_hours' => [
                        'enabled' => (bool) ($s?->peak_hours_enabled ?? false),
                        'multiplier' => (float) ($s?->peak_hours_multiplier ?? 1.0),
                        'start_time' => substr((string) ($s?->peak_hours_start_time ?? '17:00'), 0, 5),
                        'end_time' => substr((string) ($s?->peak_hours_end_time ?? '20:00'), 0, 5),
                    ],
                    'out_of_city' => [
                        'enabled' => (bool) ($s?->out_of_city_enabled ?? false),
                        'multiplier' => (float) ($s?->out_of_city_multiplier ?? 1.5),
                        'min_fare' => (int) ($s?->out_of_city_min_fare ?? 1500),
                        'inner_city_lat' => (float) ($s?->inner_city_lat ?? 6.4969),
                        'inner_city_lng' => (float) ($s?->inner_city_lng ?? 2.6289),
                        'inner_city_radius_km' => (int) ($s?->inner_city_radius_km ?? 15),
                    ],
                    'pickup_grace_period_m' => (int) ($s?->pickup_grace_period_m ?? 5),
                    'pickup_waiting_rate_per_min' => (int) ($s?->pickup_waiting_rate_per_min ?? 10),
                ];
            });
            $businessModel = $this->economicModel->get();

            // Distance facturée : PRIORITÉ à l'odomètre serveur (trace GPS réelle accumulée
            // depuis les pings pendant la course), sinon la valeur rapportée par l'app
            // (estimation). On facture ainsi le MOUVEMENT RÉEL du chauffeur, pas la distance
            // vers la destination saisie. Tout passe par le garde-fou clampCompletionDistanceMeters.
            // Distance estimée (calculée à la création, route pickup→destination saisie) — pour l'audit.
            $estimatedM = (int) ($ride->distance_m ?? 0);

            $trackedM = null;
            try {
                $track = Cache::store('redis')->get("ride:track:{$ride->id}");
                if (is_array($track) && isset($track['dist_m'])) {
                    $trackedM = (int) $track['dist_m'];
                }
            } catch (\Throwable $e) {
                // Redis indisponible : on retombe sur la valeur rapportée.
            }

            // Trace serveur fiable si elle a accumulé un trajet réel (≥ 300 m), sinon fallback app.
            $useTracked = ($trackedM !== null && $trackedM >= 300);
            $distanceSource = $useTracked ? 'tracked' : 'estimate';
            $distanceToBill = $useTracked ? $trackedM : $reportedDistanceM;

            if ($distanceToBill !== null) {
                $ride->distance_m = $this->clampCompletionDistanceMeters($ride, (int) $distanceToBill, $distanceSource);
                $ride->save();
            }

            // Nettoyage de la trace (course terminée)
            try { Cache::store('redis')->forget("ride:track:{$ride->id}"); } catch (\Throwable $e) { /* noop */ }

            // 1. Prix trajectoire (Base + Distance)
            $distanceKm = ($ride->distance_m ?? 0) / 1000.0;
            $trajectoryPrice = $pricing['base_fare'] + ($distanceKm * $pricing['per_km']);

            if ($ride->vehicle_type === 'vip') {
                $trajectoryPrice *= 1.5;
            }

            // Hors Zone (inter-urbain) — aligné sur l'estimation : majoration si départ OU arrivée hors du rayon urbain
            $oc = $pricing['out_of_city'] ?? null;
            if ($oc && ($oc['enabled'] ?? false)
                && $ride->pickup_lat && $ride->pickup_lng && $ride->dropoff_lat && $ride->dropoff_lng) {
                $distPickupKm = $this->haversineDistance((float) $ride->pickup_lat, (float) $ride->pickup_lng, (float) $oc['inner_city_lat'], (float) $oc['inner_city_lng']) / 1000.0;
                $distDropoffKm = $this->haversineDistance((float) $ride->dropoff_lat, (float) $ride->dropoff_lng, (float) $oc['inner_city_lat'], (float) $oc['inner_city_lng']) / 1000.0;
                if ($distPickupKm > $oc['inner_city_radius_km'] || $distDropoffKm > $oc['inner_city_radius_km']) {
                    $trajectoryPrice *= (float) $oc['multiplier'];
                    if (!empty($oc['min_fare'])) {
                        $pricing['min_fare'] = max($pricing['min_fare'], (int) $oc['min_fare']);
                    }
                }
            }

            if ($pricing['peak_hours']['enabled'] && $this->isCurrentlyInTimeRange($pricing['peak_hours']['start_time'], $pricing['peak_hours']['end_time'])) {
                $trajectoryPrice *= $pricing['peak_hours']['multiplier'];
            }

            if ($pricing['weather']['enabled']) {
                $trajectoryPrice *= $pricing['weather']['multiplier'];
            }

            if ($pricing['night']['multiplier'] > 1.0 && $this->isCurrentlyInTimeRange($pricing['night']['start_time'], $pricing['night']['end_time'])) {
                $trajectoryPrice *= $pricing['night']['multiplier'];
            }

            $trajectoryPrice = max($pricing['min_fare'], (int) round($trajectoryPrice));

            // 2. Prix des arrêts (bouton "Arrêt" pendant la course)
            $stopMinutes = floor($ride->total_stop_duration_s / 60.0);
            $stopPrice = (int) ($stopMinutes * $pricing['stop_rate_per_min']);

            // 2bis. Attente à la prise en charge (au-delà du délai de grâce) — aligné sur le breakdown
            $pickupWaitingPrice = 0;
            if ($ride->arrived_at) {
                $endWait = $ride->started_at ?? now();
                $waitSeconds = (int) $endWait->diffInSeconds($ride->arrived_at, true);
                $graceSeconds = ($pricing['pickup_grace_period_m'] ?? 5) * 60;
                if ($waitSeconds > $graceSeconds) {
                    $pickupWaitMinutes = floor(($waitSeconds - $graceSeconds) / 60.0);
                    $pickupWaitingPrice = (int) ($pickupWaitMinutes * ($pricing['pickup_waiting_rate_per_min'] ?? 10));
                }
            }

            // 2ter. Temps de course après prise en charge : per_min × minutes entre started_at
            // et la complétion, hors arrêts (déjà facturés au stop_rate_per_min).
            $timeFare = 0;
            $rideMinutes = 0;
            if ($ride->started_at) {
                $rideSeconds = (int) now()->diffInSeconds($ride->started_at, true);
                $rideSeconds = max(0, $rideSeconds - (int) ($ride->total_stop_duration_s ?? 0));
                $rideMinutes = (int) floor($rideSeconds / 60.0);
                $timeFare = (int) ($rideMinutes * $pricing['per_min']);
            }

            // Tarif final
            $luggageCount = (int) ($ride->luggage_count ?? ($ride->has_baggage ? 1 : 0));
            $luggageFee = $luggageCount * ($pricing['luggage_unit_price'] ?? 500);

            $originalFare = $ride->negotiated_fare !== null 
                ? (int) $ride->negotiated_fare 
                : ($trajectoryPrice + $timeFare + $stopPrice + $pickupWaitingPrice + $luggageFee);

            // La remise est figée lors de la commande. On ne la recalcule jamais à
            // la complétion : un changement de promo ou de grille ne doit pas modifier
            // le prix déjà annoncé au passager.
            $discountAmount = max(0, (float) ($ride->discount_amount ?? 0));

            if ($discountAmount > $originalFare) {
                $discountAmount = $originalFare;
            }

            $fare = (int) max(0, round($originalFare - $discountAmount));

            // Arrondi au centième supérieur (ex: 1456 -> 1500)
            $fare = (int) (ceil($fare / 100) * 100);

            $ride->original_fare_amount = $originalFare;
            $ride->discount_amount = $discountAmount;
            $ride->fare_amount = $fare;

            $ride->breakdown = [
                'base_fare' => $pricing['base_fare'],
                'trajectory_fare' => $trajectoryPrice,
                'time_fare' => $timeFare,
                'ride_minutes' => $rideMinutes,
                'per_min_rate' => $pricing['per_min'],
                'stop_fare' => $stopPrice,
                'pickup_waiting_fare' => $pickupWaitingPrice,
                'luggage_fare' => $luggageFee,
                'original_fare' => $originalFare,
                'discount_amount' => $discountAmount,
                'total_fare' => $fare,
                // Audit distance : comparer estimation (route saisie) vs réel mesuré (odomètre GPS)
                'estimated_distance_m' => $estimatedM,
                'tracked_distance_m' => $trackedM,
                'billed_distance_m' => (int) ($ride->distance_m ?? 0),
                'distance_source' => $distanceSource,
            ];

            // 3. Pas de commission sur le tarif : le zem conserve 100 % du prix
            // avant remise. Une promotion Kêkênon est donc financée par la plateforme
            // et ne réduit jamais le revenu du conducteur.
            $driverAmount = (int) $originalFare;
            $ride->commission_amount = 0;
            $ride->driver_earnings_amount = $driverAmount;
            $ride->status = 'completed';
            $ride->completed_at = now();

            // En espèces, le zem encaisse le montant remisé auprès du passager ; la
            // différence est créditée sur son portefeuille comme subvention promotionnelle.
            if (($ride->payment_method ?? 'cash') === 'cash' && $discountAmount > 0) {
                $driverWallet = DB::table('wallets')->where('user_id', $driver->id)->lockForUpdate()->first();
                if (!$driverWallet) {
                    $driverWalletId = DB::table('wallets')->insertGetId([
                        'user_id' => $driver->id,
                        'balance' => 0,
                        'currency' => $ride->currency ?? 'XOF',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $driverWallet = (object) ['id' => $driverWalletId, 'balance' => 0];
                }

                $subsidy = (int) round($discountAmount);
                $subsidyBefore = (int) $driverWallet->balance;
                $subsidyAfter = $subsidyBefore + $subsidy;
                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $driverWallet->id,
                    'type' => 'credit',
                    'source' => 'promo_subsidy',
                    'amount' => $subsidy,
                    'balance_before' => $subsidyBefore,
                    'balance_after' => $subsidyAfter,
                    'meta' => json_encode(['ride_id' => $ride->id, 'desc' => 'Remise passager financée par Kêkênon']),
                    'created_at' => now(),
                ]);
                DB::table('wallets')->where('id', $driverWallet->id)->update([
                    'balance' => $subsidyAfter,
                    'updated_at' => now(),
                ]);
            }

            // Décrémenter l'abonnement du chauffeur de 1 course
            DB::table('driver_profiles')
                ->where('user_id', $driver->id)
                ->decrement('subscription_remaining_rides', 1);

            // Renouvellement automatique : avoir de l'argent (solde ou bonus) = avoir
            // le pack. À épuisement, le prix configuré est prélevé (solde d'abord,
            // bonus en complément) et le nombre de courses configuré est recrédité. Sans fonds
            // suffisants, le compteur reste épuisé et l'app invite à recharger.
            $this->autoRenewSubscriptionIfNeeded($driver->id, (int) $ride->id, $businessModel);

            // Frais d'application passager, configurables depuis le panel.
            $passengerWallet = DB::table('wallets')->where('user_id', $ride->rider_id)->lockForUpdate()->first();
            if (!$passengerWallet) {
                $pWid = DB::table('wallets')->insertGetId([
                    'user_id' => $ride->rider_id,
                    'balance' => 0,
                    'currency' => $ride->currency ?? 'XOF',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $passengerWallet = (object) ['id' => $pWid, 'balance' => 0];
            }
            // Modèle zéro dette : on ne prélève jamais au-delà du solde disponible.
            $pBefore = (int) $passengerWallet->balance;
            $configuredPassengerFee = (int) $businessModel['passenger_app_fee'];
            $pFee = min($configuredPassengerFee, max(0, $pBefore));
            if ($pFee > 0) {
                $pAfter = $pBefore - $pFee;
                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $passengerWallet->id,
                    'type' => 'debit',
                    'source' => 'app_fee',
                    'amount' => $pFee,
                    'balance_before' => $pBefore,
                    'balance_after' => $pAfter,
                    'meta' => json_encode([
                        'ride_id' => $ride->id,
                        'desc' => 'Frais de plateforme Kêkênon',
                        'configured_fee' => $configuredPassengerFee,
                    ]),
                    'created_at' => now(),
                ]);
                DB::table('wallets')->where('id', $passengerWallet->id)->update([
                    'balance' => $pAfter,
                    'updated_at' => now(),
                ]);
            }

            $ride->save();

            // 4. Mouvements de wallet selon le mode de paiement (aucun prélèvement de commission)
            $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();
            if (!$wallet) {
                $walletId = DB::table('wallets')->insertGetId([
                    'user_id' => $driver->id,
                    'balance' => 0,
                    'currency' => $ride->currency ?? 'XOF',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $wallet = (object) ['id' => $walletId, 'balance' => 0];
            }

            $before = (int) $wallet->balance;
            $after = $before;

            $pm = $ride->payment_method ?? 'cash';

            if ($pm === 'cash') {
                $ride->payment_status = 'completed';
                $ride->save();
            } elseif ($pm === 'wallet') {
                // Wallet : on attend la confirmation du passager via /passenger/rides/{id}/pay
            } elseif ($pm === 'qr' || $pm === 'card' || $pm === 'mobile_money') {
                $ride->payment_status = 'pending';
                $ride->save();
            }

            if ($before !== $after) {
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $after,
                    'updated_at' => now(),
                ]);
            }

            $ride->refresh();

            return [
                'ride' => $ride,
                'driverAmount' => $driverAmount,
            ];
        });

        // Broadcast APRÈS commit
        rescue(fn () => broadcast(new RideCompleted($result['ride'])));

        // Notifier le passager via FCM
        try {
            $passenger = $result['ride']->rider;
            if ($passenger) {
                $this->fcm->sendToUser(
                    $passenger,
                    'Course terminée !',
                    "Merci d'avoir voyagé avec TIC. Tarif: " . number_format($result['ride']->fare_amount, 0, ',', ' ') . ' FCFA',
                    ['ride_id' => (string) $result['ride']->id, 'type' => 'ride_completed']
                );
            }
        } catch (\Exception $e) {
            Log::error('FCM Ride Completed Notification Error: ' . $e->getMessage());
        }

        Log::info('RideCompleted broadcast sent', [
            'ride_id' => $result['ride']->id,
            'rider_id' => $result['ride']->rider_id,
            'status' => $result['ride']->status,
        ]);

        return $result;
    }

    /**
     * Renouvelle l'abonnement depuis le portefeuille dès que le pack est épuisé
     * (compteur ≤ 0) : débit du solde principal d'abord, bonus en complément.
     * Doit être appelé DANS la transaction de complétion.
     */
    private function autoRenewSubscriptionIfNeeded(int $driverId, int $rideId, array $businessModel): void
    {
        $remaining = DB::table('driver_profiles')
            ->where('user_id', $driverId)
            ->value('subscription_remaining_rides');

        if ($remaining === null || (int) $remaining > 0) {
            return; // Pas de profil, ou pack encore actif.
        }

        $wallet = DB::table('wallets')->where('user_id', $driverId)->lockForUpdate()->first();
        if (!$wallet) {
            return;
        }

        $balance = (int) $wallet->balance;
        $bonus = (int) ($wallet->bonus_balance ?? 0);
        $packPrice = (int) $businessModel['driver_pack_price'];
        $packRides = (int) $businessModel['driver_pack_rides'];
        if ($balance + $bonus < $packPrice) {
            return; // Zéro dette : pas de fonds, pas de renouvellement.
        }

        $fromBalance = min($balance, $packPrice);
        $fromBonus = $packPrice - $fromBalance;
        $afterBalance = $balance - $fromBalance;
        $afterBonus = $bonus - $fromBonus;

        DB::table('wallets')->where('id', $wallet->id)->update([
            'balance' => $afterBalance,
            'bonus_balance' => $afterBonus,
            'updated_at' => now(),
        ]);

        if ($fromBalance > 0) {
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'source' => 'subscription_fee',
                'amount' => $fromBalance,
                'balance_before' => $balance,
                'balance_after' => $afterBalance,
                'meta' => json_encode([
                    'desc' => "Renouvellement auto — pack {$packRides} courses",
                    'auto' => true,
                    'ride_id' => $rideId,
                    'bonus_used' => $fromBonus,
                ]),
                'created_at' => now(),
            ]);
        }
        if ($fromBonus > 0) {
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'source' => 'subscription_fee_bonus',
                'amount' => $fromBonus,
                // Le solde principal ne bouge pas sur la part bonus
                'balance_before' => $afterBalance,
                'balance_after' => $afterBalance,
                'meta' => json_encode([
                    'desc' => 'Renouvellement auto payé avec le bonus Kêkênon',
                    'auto' => true,
                    'ride_id' => $rideId,
                    'bonus_before' => $bonus,
                    'bonus_after' => $afterBonus,
                ]),
                'created_at' => now(),
            ]);
        }

        DB::table('driver_profiles')
            ->where('user_id', $driverId)
            ->increment('subscription_remaining_rides', $packRides);

        Log::info('Subscription auto-renewed from wallet', [
            'driver_id' => $driverId,
            'ride_id' => $rideId,
            'from_balance' => $fromBalance,
            'from_bonus' => $fromBonus,
            'pack_price' => $packPrice,
            'pack_rides' => $packRides,
        ]);
    }

    private function isCurrentlyInTimeRange(string $start, string $end): bool
    {
        $nowTime = now()->format('H:i');
        if ($start <= $end) {
            return $nowTime >= $start && $nowTime <= $end;
        }

        return $nowTime >= $start || $nowTime <= $end;
    }

    /** Vitesse moyenne max plausible (km/h) pour borner la distance par le temps de course. */
    private const MAX_PLAUSIBLE_SPEED_KMH = 90;

    /**
     * Bornage de la distance facturée autour de la valeur RÉELLE rapportée (odomètre GPS).
     * Trois garde-fous, tous conditionnés à la cohérence avec le temps de course :
     *   - PLAFOND vitesse : distance ≤ temps × 90 km/h (anti sur-déclaration / saut GPS).
     *   - PLAFOND spatial : distance ≤ 2,6 × vol d'oiseau (+10 km) (anti détour absurde).
     *   - PLANCHER vol d'oiseau : distance ≥ distance directe départ→arrivée, MAIS seulement
     *     si ce plancher est lui-même plausible en temps (sinon coords fausses → on ne force
     *     pas, pour ne pas recréer la sur-facturation type #443). Corrige les sous-comptes GPS.
     */
    private function clampCompletionDistanceMeters(Ride $ride, int $reported, string $source = 'estimate'): int
    {
        $reported = max(0, min($reported, 600_000));

        // Garde-fou temporel : la distance ne peut dépasser temps_de_course × vitesse_max.
        // Attrape les sauts GPS et les sur-déclarations (ex. 40 km en 19 min ≈ 126 km/h).
        if ($ride->started_at) {
            $rideSeconds = (int) now()->diffInSeconds($ride->started_at, true);
            if ($rideSeconds > 0) {
                $maxBySpeed = (int) round(($rideSeconds / 3600.0) * self::MAX_PLAUSIBLE_SPEED_KMH * 1000);
                if ($maxBySpeed > 0 && $reported > $maxBySpeed) {
                    Log::warning('complete: distance_m clamped by speed', [
                        'ride_id' => $ride->id,
                        'reported' => $reported,
                        'max_by_speed' => $maxBySpeed,
                        'ride_seconds' => $rideSeconds,
                    ]);
                    $reported = $maxBySpeed;
                }
            }
        }

        $pickupLat = $ride->pickup_lat;
        $pickupLng = $ride->pickup_lng;
        $dropLat = $ride->dropoff_lat;
        $dropLng = $ride->dropoff_lng;

        if ($pickupLat === null || $pickupLng === null || $dropLat === null || $dropLng === null) {
            return min($reported, 400_000);
        }

        $straightM = (int) round($this->haversineDistance(
            (float) $pickupLat,
            (float) $pickupLng,
            (float) $dropLat,
            (float) $dropLng
        ));

        if ($straightM <= 0) {
            return min($reported, 400_000);
        }

        // Plafond spatial (~2,6× vol d'oiseau + 10 km) : appliqué UNIQUEMENT au repli
        // "estimation" (protège des coordonnées fausses, type #443). Quand on a une VRAIE
        // mesure GPS (source 'tracked'), déjà bornée par la vitesse plus haut, on FAIT
        // CONFIANCE à l'odomètre : sinon on sous-facturerait les aller-retours (départ≈arrivée,
        // vol d'oiseau ≈ 0) — cf. course #526 (42 km réels coupés à tort à 10,5 km).
        $spatialRef = (int) min((int) max($straightM * 2.6 + 10_000, $straightM + 5_000), 600_000);

        if ($source !== 'tracked') {
            if ($reported > $spatialRef) {
                Log::warning('complete: distance_m clamped (spatial, source=estimate)', [
                    'ride_id' => $ride->id,
                    'reported' => $reported,
                    'max_allowed' => $spatialRef,
                    'straight_m' => $straightM,
                ]);

                return $spatialRef;
            }
        } elseif ($reported > $spatialRef) {
            // GPS réel facturé au-delà du plafond spatial habituel (gros détour / aller-retour) :
            // on facture le réel mais on SIGNALE pour revue éventuelle (anti-abus chauffeur).
            Log::warning('complete: distance réelle GPS au-delà du plafond spatial (facturée, à revoir)', [
                'ride_id' => $ride->id,
                'tracked_m' => $reported,
                'spatial_ref_m' => $spatialRef,
                'straight_m' => $straightM,
            ]);
        }

        // PLANCHER intelligent : on ne peut pas rouler MOINS que la distance à vol d'oiseau
        // entre départ et arrivée. Appliqué UNIQUEMENT si ce plancher est cohérent avec le
        // temps (sinon coords suspectes → on ne force pas, pour ne pas recréer la
        // sur-facturation type #443). Corrige les sous-comptes GPS (pings rares / trafic lent).
        if ($reported < $straightM && $ride->started_at) {
            $rideSeconds = (int) now()->diffInSeconds($ride->started_at, true);
            $straightSpeedKmh = $rideSeconds > 0 ? ($straightM / $rideSeconds) * 3.6 : 999;
            if ($straightSpeedKmh <= self::MAX_PLAUSIBLE_SPEED_KMH) {
                Log::info('complete: distance_m floored to straight-line', [
                    'ride_id' => $ride->id,
                    'reported' => $reported,
                    'straight_m' => $straightM,
                    'straight_speed_kmh' => round($straightSpeedKmh, 1),
                ]);
                $reported = $straightM;
            }
        }

        return $reported;
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
