<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PassengerInboxNotification;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pousse les campagnes « Communication » (table notifications) vers l’inbox passager
 * (badge header + écran Notifications de l’app).
 */
class PassengerCampaignInboxDispatcher
{
    private const PASSENGER_TARGETS = ['all_passengers', 'active_passengers'];

    public function shouldDispatch(Notification $notification): bool
    {
        return in_array($notification->target, self::PASSENGER_TARGETS, true);
    }

    /**
     * IDs passagers concernés par la campagne (pour FCM, stats, etc.).
     *
     * @return Collection<int, int>
     */
    public function recipientUserIds(Notification $notification): Collection
    {
        if (!$this->shouldDispatch($notification)) {
            return collect();
        }

        return $this->resolvePassengerUserIds($notification->target)->unique()->values();
    }

    public function sync(Notification $notification): void
    {
        DB::transaction(function () use ($notification) {
            if (!$this->shouldDispatch($notification)) {
                PassengerInboxNotification::query()
                    ->where('admin_notification_id', $notification->id)
                    ->delete();

                return;
            }

            $userIds = $this->resolvePassengerUserIds($notification->target)->unique()->values();
            if ($userIds->isEmpty()) {
                PassengerInboxNotification::query()
                    ->where('admin_notification_id', $notification->id)
                    ->delete();

                return;
            }

            PassengerInboxNotification::query()
                ->where('admin_notification_id', $notification->id)
                ->whereNotIn('user_id', $userIds)
                ->delete();

            $type = $notification->type ?: 'system';
            PassengerInboxNotification::query()
                ->where('admin_notification_id', $notification->id)
                ->update([
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'type' => $type,
                    'updated_at' => now(),
                ]);

            $already = PassengerInboxNotification::query()
                ->where('admin_notification_id', $notification->id)
                ->pluck('user_id');
            $missing = $userIds->diff($already);
            if ($missing->isEmpty()) {
                return;
            }

            $now = now();
            $rows = $missing->map(fn (int $uid) => [
                'user_id' => $uid,
                'admin_notification_id' => $notification->id,
                'title' => $notification->title,
                'message' => $notification->message,
                'type' => $type,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 400) as $chunk) {
                DB::table('passenger_inbox_notifications')->insert($chunk);
            }
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function resolvePassengerUserIds(string $target)
    {
        $q = User::query()->where('role', 'passenger');

        if ($target === 'active_passengers') {
            $since = now()->subDays(30);
            $activeIds = Ride::query()
                ->where('created_at', '>=', $since)
                ->whereNotNull('rider_id')
                ->distinct()
                ->pluck('rider_id');

            return $q->whereIn('id', $activeIds)->pluck('id');
        }

        return $q->pluck('id');
    }
}
