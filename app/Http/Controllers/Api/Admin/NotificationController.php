<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\PassengerCampaignInboxDispatcher;
use App\Services\PassengerCampaignPushDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function __construct(
        private readonly PassengerCampaignInboxDispatcher $passengerCampaignInbox,
        private readonly PassengerCampaignPushDispatcher $passengerCampaignPush,
    ) {
    }
    /**
     * Store a newly created notification in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target' => 'required|string',
            'channels' => 'required|array',
            'type' => 'sometimes|string|max:32',
        ]);

        $notification = DB::transaction(function () use ($request) {
            $n = Notification::create([
                'title' => $request->title,
                'message' => $request->message,
                'target' => $request->target,
                'channels' => $request->channels,
                'type' => $request->input('type', 'system'),
            ]);
            $this->passengerCampaignInbox->sync($n);

            return $n;
        });

        $this->passengerCampaignPush->sendIfEligible($notification);

        return response()->json($notification, 201);
    }

    /**
     * Display a list of notifications sent (History).
     */
    public function index()
    {
        $notifications = Notification::orderBy('created_at', 'desc')->limit(50)->get();
        return response()->json($notifications);
    }

    /**
     * §20.10 — Mesure des campagnes.
     * Envoyés + taux d'ouverture sont RÉELS (via passenger_inbox_notifications.read_at).
     * Le clic et la conversion ne sont pas encore suivis (nécessitent une instrumentation app).
     */
    public function campaigns()
    {
        $notifications = Notification::orderBy('created_at', 'desc')->limit(100)->get();

        $inboxStats = DB::table('passenger_inbox_notifications')
            ->whereNotNull('admin_notification_id')
            ->selectRaw('admin_notification_id, COUNT(*) as recipients, SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as opened')
            ->groupBy('admin_notification_id')
            ->get()->keyBy('admin_notification_id');

        $rows = $notifications->map(function ($n) use ($inboxStats) {
            $s = $inboxStats->get($n->id);
            $recipients = $s ? (int) $s->recipients : 0;
            $opened = $s ? (int) $s->opened : 0;

            return [
                'id' => $n->id,
                'title' => $n->title,
                'type' => $n->type,
                'target' => $n->target,
                'channels' => $n->channels,
                'created_at' => $n->created_at,
                'recipients' => $recipients,
                'opened' => $opened,
                'open_rate_pct' => $recipients > 0 ? round(($opened / $recipients) * 100, 1) : null,
                'tracked' => $recipients > 0, // métriques d'ouverture dispo uniquement pour les campagnes passager (inbox)
            ];
        });

        $totalRecipients = (int) $rows->sum('recipients');
        $totalOpened = (int) $rows->sum('opened');

        return response()->json([
            'summary' => [
                'total_campaigns' => $rows->count(),
                'total_recipients' => $totalRecipients,
                'total_opened' => $totalOpened,
                'avg_open_rate_pct' => $totalRecipients > 0 ? round(($totalOpened / $totalRecipients) * 100, 1) : null,
            ],
            'campaigns' => $rows,
        ]);
    }

    /**
     * Met à jour une notification (brouillon / campagne enregistrée — pas d’envoi push automatique ici).
     */
    public function update(Request $request, int $id)
    {
        $notification = Notification::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target' => 'required|string',
            'channels' => 'required|array',
            'type' => 'sometimes|string|max:32',
        ]);

        DB::transaction(function () use ($request, $notification) {
            $notification->fill([
                'title' => $request->title,
                'message' => $request->message,
                'target' => $request->target,
                'channels' => $request->channels,
                'type' => $request->input('type', $notification->type),
            ]);
            $notification->save();
            $this->passengerCampaignInbox->sync($notification);
        });

        $fresh = $notification->fresh();
        if ($fresh) {
            $this->passengerCampaignPush->sendIfEligible($fresh);
        }

        return response()->json($fresh);
    }

    /**
     * Supprime une campagne enregistrée (historique Communication).
     */
    public function destroy(int $id)
    {
        $notification = Notification::findOrFail($id);
        $notification->delete();

        return response()->json(['ok' => true]);
    }
}
