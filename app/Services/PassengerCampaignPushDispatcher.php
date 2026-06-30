<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Envoie une notification FCM (hors app / arrière-plan) pour les campagnes Communication
 * lorsque le canal « Push » est coché et que la cible est passager.
 */
class PassengerCampaignPushDispatcher
{
    public function __construct(
        private readonly FcmService $fcm,
        private readonly PassengerCampaignInboxDispatcher $audience,
    ) {
    }

    public function sendIfEligible(Notification $notification): void
    {
        if (!$this->channelIncludesPush($notification)) {
            return;
        }

        $userIds = $this->audience->recipientUserIds($notification);
        if ($userIds->isEmpty()) {
            return;
        }

        $tokens = FcmToken::query()
            ->whereIn('user_id', $userIds->all())
            ->pluck('token')
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        if ($tokens === []) {
            Log::info('PassengerCampaignPush: aucun jeton FCM pour les passagers ciblés (appareils non enregistrés ou permissions refusées).');

            return;
        }

        $type = (string) ($notification->type ?: 'system');
        $data = [
            'type' => $type,
            'campaign_id' => (string) $notification->id,
        ];

        $title = (string) $notification->title;
        $plain = strip_tags((string) $notification->message);
        $body = mb_strlen($plain) > 350 ? mb_substr($plain, 0, 347) . '…' : $plain;

        foreach (array_chunk($tokens, 80) as $chunk) {
            $this->fcm->sendToTokens($chunk, $title, $body, $data);
        }
    }

    private function channelIncludesPush(Notification $notification): bool
    {
        $channels = $notification->channels;
        if (!is_array($channels)) {
            return false;
        }
        foreach ($channels as $c) {
            if (strtolower((string) $c) === 'push') {
                return true;
            }
        }

        return false;
    }
}
