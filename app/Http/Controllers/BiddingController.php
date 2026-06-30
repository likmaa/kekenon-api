<?php

namespace App\Http\Controllers;

use App\Events\BidAccepted;
use App\Events\BidSubmitted;
use App\Models\Ride;
use App\Models\RideBid;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BiddingController extends Controller
{
    protected function apiError(string $code, string $message, int $status): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $code, 'message' => $message], $status);
    }

    /**
     * POST /rides/{id}/bid
     * 
     * Soumet une proposition ou contre-proposition de prix (passager ou chauffeur).
     */
    public function submitBid(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $data = $request->validate([
            'proposed_fare' => ['required', 'integer', 'min:100', 'max:500000'],
        ]);

        $ride = Ride::find($id);

        if (!$ride) {
            return $this->apiError('NOT_FOUND', 'Course introuvable.', 404);
        }

        if ($ride->pricing_mode !== 'negotiable') {
            return $this->apiError('INVALID_MODE', 'Cette course n\'est pas en mode négociation.', 422);
        }

        // La négociation a lieu après l'acceptation (assignation d'un chauffeur)
        if (!in_array($ride->status, ['accepted', 'requested'])) {
            return $this->apiError('INVALID_STATE', 'Cette course n\'est plus ouverte à la négociation.', 422);
        }

        // L'utilisateur doit être soit le passager, soit le chauffeur de la course
        $isRider = (int) $ride->rider_id === (int) $user->id;
        $isDriver = $ride->driver_id && (int) $ride->driver_id === (int) $user->id;

        if (!$isRider && !$isDriver) {
            return $this->apiError('FORBIDDEN', 'Vous ne faites pas partie de cette course.', 403);
        }

        return DB::transaction(function () use ($ride, $user, $data) {
            // Décliner les offres précédentes de ce même utilisateur pour cette course
            RideBid::where('ride_id', $ride->id)
                ->where('sender_id', $user->id)
                ->where('status', 'pending')
                ->update(['status' => 'declined']);

            // Créer la nouvelle offre
            $bid = RideBid::create([
                'ride_id'       => $ride->id,
                'sender_id'     => $user->id,
                'proposed_fare' => $data['proposed_fare'],
                'status'        => 'pending',
            ]);

            $bid->load(['sender', 'sender.driverProfile']);

            // Diffuser l'offre en temps réel à l'autre partie via le canal privé de la course
            try {
                broadcast(new BidSubmitted($bid))->toOthers();
            } catch (\Exception $e) {
                Log::error('[BiddingController] BidSubmitted broadcast failed', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'ok'  => true,
                'bid' => $bid->toPublicArray(),
            ], 201);
        });
    }

    /**
     * GET /rides/{id}/bids
     * 
     * Liste l'historique complet des offres échangées pour cette course.
     */
    public function listBids(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $ride = Ride::find($id);

        if (!$ride) {
            return $this->apiError('NOT_FOUND', 'Course introuvable.', 404);
        }

        $isRider = (int) $ride->rider_id === (int) $user->id;
        $isDriver = $ride->driver_id && (int) $ride->driver_id === (int) $user->id;

        if (!$isRider && !$isDriver) {
            return $this->apiError('FORBIDDEN', 'Accès non autorisé.', 403);
        }

        $bids = RideBid::with(['sender', 'sender.driverProfile'])
            ->where('ride_id', $id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($bid) => $bid->toPublicArray());

        return response()->json([
            'ok'   => true,
            'bids' => $bids,
        ]);
    }

    /**
     * POST /rides/{id}/accept-bid/{bidId}
     * 
     * Confirme et valide l'offre de prix sélectionnée (par le passager ou le chauffeur).
     */
    public function acceptBid(Request $request, int $id, int $bidId): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        try {
            return DB::transaction(function () use ($user, $id, $bidId) {
                $ride = Ride::lockForUpdate()->find($id);

                if (!$ride) {
                    return $this->apiError('NOT_FOUND', 'Course introuvable.', 404);
                }

                $isRider = (int) $ride->rider_id === (int) $user->id;
                $isDriver = $ride->driver_id && (int) $ride->driver_id === (int) $user->id;

                if (!$isRider && !$isDriver) {
                    return $this->apiError('FORBIDDEN', 'Accès non autorisé.', 403);
                }

                $bid = RideBid::lockForUpdate()
                    ->where('id', $bidId)
                    ->where('ride_id', $id)
                    ->where('status', 'pending')
                    ->first();

                if (!$bid) {
                    return $this->apiError('NOT_FOUND', 'Offre introuvable ou déjà traitée.', 404);
                }

                // On ne peut accepter que l'offre reçue de l'autre partie
                if ((int) $bid->sender_id === (int) $user->id) {
                    return $this->apiError('INVALID_ACTION', 'Vous ne pouvez pas accepter votre propre offre.', 422);
                }

                // 1. Accepter l'offre
                $bid->update(['status' => 'accepted']);

                // 2. Décliner les autres propositions en attente
                RideBid::where('ride_id', $id)
                    ->where('id', '!=', $bidId)
                    ->where('status', 'pending')
                    ->update(['status' => 'declined']);

                // 3. Mettre à jour le tarif convenu sur la course
                $ride->update([
                    'fare_amount'            => $bid->proposed_fare,
                    'negotiated_fare'        => $bid->proposed_fare,
                    'bid_accepted_driver_id' => $ride->driver_id,
                ]);

                // 4. Diffuser l'acceptation en temps réel aux deux apps via le canal de la course
                try {
                    broadcast(new BidAccepted($bid))->toOthers();
                } catch (\Exception $e) {
                    Log::error('[BiddingController] BidAccepted broadcast failed', ['error' => $e->getMessage()]);
                }

                // 5. Envoyer une notification FCM d'acceptation
                try {
                    $fcmService = app(FcmService::class);
                    $recipientId = $isRider ? $ride->driver_id : $ride->rider_id;
                    if ($recipientId) {
                        $fcmService->sendToUser($recipientId, [
                            'title' => '🎉 Tarif convenu !',
                            'body'  => "Le tarif de {$bid->proposed_fare} FCFA a été accepté pour votre course.",
                            'data'  => [
                                'type'    => 'bid_accepted',
                                'rideId'  => (string) $ride->id,
                                'fare'    => (string) $bid->proposed_fare,
                            ],
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('[BiddingController] FCM notification failed', ['error' => $e->getMessage()]);
                }

                return response()->json([
                    'ok'   => true,
                    'ride' => [
                        'id'          => $ride->id,
                        'status'      => $ride->status,
                        'fare_amount' => $ride->fare_amount,
                    ],
                ]);
            });
        } catch (\Exception $e) {
            Log::error('[BiddingController] acceptBid failed', ['error' => $e->getMessage()]);
            return $this->apiError('SERVER_ERROR', 'Erreur lors de l\'acceptation du prix.', 500);
        }
    }
}
