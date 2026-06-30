<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FcmTokenController extends Controller
{
    /**
     * Register or update an FCM token for the authenticated user.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'device_type' => ['nullable', 'string', 'in:ios,android,web'],
        ]);

        $user = Auth::user();

        $fcmToken = FcmToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'token' => $data['token'],
            ],
            [
                'device_type' => $data['device_type'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Token registered successfully',
            'data' => $fcmToken,
        ]);
    }

    /**
     * Remove an FCM token (e.g., on logout).
     */
    public function unregister(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        FcmToken::where('user_id', Auth::id())
            ->where('token', $request->token)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Token unregistered successfully',
        ]);
    }
}
