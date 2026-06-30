<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function broadcast(Request $request)
    {
        $data = $request->validate([
            'title' => ['required','string','max:120'],
            'body' => ['required','string','max:500'],
            'role' => ['sometimes','in:driver,passenger,all'],
        ]);

        return response()->json([
            'ok' => true,
            'enqueued' => true,
            'target' => $data['role'] ?? 'all',
        ]);
    }
}
