<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PassengerInboxNotification;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Inbox notifications passager (table passenger_inbox_notifications) — outils QA depuis developer-dashboard.
 * Rôle developer uniquement (routes api.php).
 */
class DeveloperPassengerInboxController extends Controller
{
    public function index(Request $request, int $userId)
    {
        $user = User::findOrFail($userId);
        if (($user->role ?? '') !== 'passenger') {
            return response()->json(['error' => "L'utilisateur #{$userId} n'est pas un passager."], 422);
        }

        $items = PassengerInboxNotification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'sometimes|string|in:system,ride,promo',
        ]);

        $user = User::findOrFail($request->user_id);
        if (($user->role ?? '') !== 'passenger') {
            return response()->json(['error' => "L'utilisateur n'est pas un passager."], 422);
        }

        $row = PassengerInboxNotification::create([
            'user_id' => $user->id,
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->input('type', 'system'),
        ]);

        return response()->json($row, 201);
    }

    public function update(Request $request, int $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'sometimes|string|in:system,ride,promo',
        ]);

        $n = PassengerInboxNotification::findOrFail($id);
        $user = User::findOrFail($n->user_id);
        if (($user->role ?? '') !== 'passenger') {
            return response()->json(['error' => 'Incohérence : compte lié non passager.'], 422);
        }

        $n->fill([
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->input('type', $n->type),
        ]);
        $n->save();

        return response()->json($n);
    }

    public function destroy(Request $request, int $id)
    {
        $n = PassengerInboxNotification::findOrFail($id);
        $n->delete();

        return response()->json(['ok' => true]);
    }
}
