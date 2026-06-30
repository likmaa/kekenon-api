<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get list of notifications for the driver.
     * Currently fetching all 'all_drivers' notifications.
     */
    public function index(Request $request)
    {
        // Simple logic for now: get all notifications targeting 'all_drivers' or 'active_drivers'
        // In a real app, you would filter by created_at > user_created_at, or track 'read' status in a pivot table.
        // For this MVP, we just return the latest 20 notifications.

        $notifications = Notification::whereIn('target', ['all_drivers', 'active_drivers'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json($notifications);
    }
}
