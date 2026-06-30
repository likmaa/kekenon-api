<?php

namespace App\Http\Controllers\Api\Passenger;

use App\Http\Controllers\Controller;
use App\Models\PassengerInboxNotification;
use Illuminate\Http\Request;

class PassengerNotificationController extends Controller
{
    /**
     * Création de données de test (dev-seed) uniquement — pas pour la mise à jour inbox.
     */
    private function devSeedEnabled(): bool
    {
        if (config('app.debug')) {
            return true;
        }
        if (filter_var(env('ALLOW_PASSENGER_DEV_SEED', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        // Environnement local Laravel : évite le 404 « silencieux » si APP_DEBUG oublié.
        return app()->environment('local');
    }

    public function index(Request $request)
    {
        $items = PassengerInboxNotification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json($items);
    }

    public function unreadCount(Request $request)
    {
        $count = PassengerInboxNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, string $id)
    {
        $n = PassengerInboxNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        if ($n->read_at === null) {
            $n->read_at = now();
            $n->save();
        }

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        PassengerInboxNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Crée une notification inbox avec titre / message libres (panel dev uniquement).
     */
    public function devCreate(Request $request)
    {
        if (!$this->devSeedEnabled()) {
            abort(404);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'sometimes|string|in:system,ride,promo',
        ]);

        $row = PassengerInboxNotification::create([
            'user_id' => $request->user()->id,
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->input('type', 'system'),
        ]);

        return response()->json($row, 201);
    }

    /**
     * Met à jour une notification inbox (panel dev — même garde que la création).
     */
    public function updateInbox(Request $request, string $id)
    {
        if (!$this->devSeedEnabled()) {
            abort(404);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'sometimes|string|in:system,ride,promo',
        ]);

        $n = PassengerInboxNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $n->fill([
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->input('type', $n->type),
        ]);
        $n->save();

        return response()->json($n);
    }

    /**
     * Supprime une notification inbox (panel dev — même garde que la création).
     */
    public function destroyInbox(Request $request, string $id)
    {
        if (!$this->devSeedEnabled()) {
            abort(404);
        }

        $n = PassengerInboxNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $n->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Crée une ligne inbox pour le passager connecté (tests / panel dev app).
     */
    public function devSeed(Request $request)
    {
        if (!$this->devSeedEnabled()) {
            abort(404);
        }

        $row = PassengerInboxNotification::create([
            'user_id' => $request->user()->id,
            'title' => 'Notification de test',
            'message' => 'Ceci est une notification générée depuis le panel développeur (inbox passager).',
            'type' => 'system',
        ]);

        return response()->json(['ok' => true, 'id' => $row->id]);
    }
}
