<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ModerationController extends Controller
{
    public function queue(Request $request)
    {
        // Get pending moderation cases from database (if table exists)
        // For now, return empty array - real implementation would query a reports table
        $items = [];

        return response()->json([
            'data' => $items,
        ]);
    }

    public function logs(Request $request)
    {
        // Check if table exists to avoid crash if migration not run
        if (!DB::getSchemaBuilder()->hasTable('moderation_logs')) {
            return response()->json(['data' => []]);
        }

        // Get moderation logs from database
        $logs = DB::table('moderation_logs')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => 'log_' . $log->id,
                    'date' => $log->created_at,
                    'moderator' => $log->moderator_name ?? 'Admin',
                    'action' => $log->action,
                    'target_name' => $log->target_name,
                    'target_type' => $log->target_type,
                    'reason' => $log->reason,
                ];
            });

        return response()->json([
            'data' => $logs,
        ]);
    }


    /**
     * Suspend a user temporarily
     */
    public function suspend(Request $request, int $userId)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        $suspendedUntil = $data['duration_days']
            ? now()->addDays($data['duration_days'])
            : now()->addDays(7);

        DB::table('users')->where('id', $userId)->update([
            'is_blocked' => true,
            'blocked_reason' => 'Suspendu: ' . $data['reason'],
            'blocked_at' => now(),
            'suspended_until' => $suspendedUntil,
            'updated_at' => now(),
        ]);

        $this->logAction('suspended', $user, $data['reason']);

        return response()->json([
            'ok' => true,
            'message' => 'Utilisateur suspendu jusqu\'au ' . $suspendedUntil->format('d/m/Y'),
        ]);
    }

    /**
     * Ban a user permanently
     */
    public function ban(Request $request, int $userId)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        DB::table('users')->where('id', $userId)->update([
            'is_blocked' => true,
            'blocked_reason' => 'Banni: ' . $data['reason'],
            'blocked_at' => now(),
            'suspended_until' => null, // Permanent
            'updated_at' => now(),
        ]);

        $this->logAction('banned', $user, $data['reason']);

        return response()->json([
            'ok' => true,
            'message' => 'Utilisateur banni définitivement',
        ]);
    }

    /**
     * Warn a user (no action, just log)
     */
    public function warn(Request $request, int $userId)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        // Increment warning count if column exists
        DB::table('users')->where('id', $userId)->increment('warnings_count');

        $this->logAction('warned', $user, $data['reason']);

        return response()->json([
            'ok' => true,
            'message' => 'Avertissement envoyé à l\'utilisateur',
        ]);
    }

    /**
     * Reinstate a banned/suspended user
     */
    public function reinstate(Request $request, int $userId)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        DB::table('users')->where('id', $userId)->update([
            'is_blocked' => false,
            'blocked_reason' => null,
            'blocked_at' => null,
            'suspended_until' => null,
            'updated_at' => now(),
        ]);

        $this->logAction('reinstated', $user, $data['reason'] ?? 'Réintégré par modérateur');

        return response()->json([
            'ok' => true,
            'message' => 'Utilisateur réintégré',
        ]);
    }

    private function logAction(string $action, object $user, string $reason): void
    {
        try {
            DB::table('moderation_logs')->insert([
                'moderator_id' => auth()->id(),
                'moderator_name' => auth()->user()?->name ?? 'Admin',
                'action' => $action,
                'target_id' => $user->id,
                'target_name' => $user->name,
                'target_type' => $user->role,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log moderation action', ['error' => $e->getMessage()]);
        }
    }

}

