<?php

namespace App\Http\Controllers\Admin;

use App\Events\PaymentConfirmed;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class DeveloperController extends Controller
{
    private function ensureDangerousDevToolsAllowed(): ?\Illuminate\Http\JsonResponse
    {
        // Autorisé temporairement sur tous les environnements pour permettre les tests.
        return null;
    }

    /**
     * List driver documents submitted from the mobile app.
     * GET /api/admin/dev/drivers/documents?driver_id=123
     */
    public function driverDocuments(Request $request)
    {
        $data = $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $query = DriverProfile::query()
            ->with(['user:id,name,phone,email,photo,role'])
            ->orderByDesc('updated_at');

        if (!empty($data['driver_id'])) {
            $query->where('user_id', (int) $data['driver_id']);
        }

        $profiles = $query->get();

        $rows = $profiles->map(function (DriverProfile $profile) {
            $documents = $this->normalizeDriverDocuments($profile->documents);

            return [
                'driver_id' => $profile->user_id,
                'driver_name' => $profile->user?->name,
                'driver_phone' => $profile->user?->phone,
                'driver_email' => $profile->user?->email,
                'driver_status' => $profile->status,
                'profile_updated_at' => $profile->updated_at,
                'documents_count' => count($documents),
                'documents' => $documents,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'count' => $rows->count(),
            'items' => $rows,
        ]);
    }

    /**
     * Validate/reject/update one driver document status.
     * POST /api/admin/dev/drivers/documents/validate
     */
    public function validateDriverDocument(Request $request)
    {
        $data = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:users,id'],
            'document_key' => ['required', 'string', 'max:120'],
            'status' => ['required', 'string', 'in:valid,pending,expired,rejected,approved'],
            'expiry' => ['nullable', 'string', 'max:100'],
        ]);

        $profile = DriverProfile::query()->where('user_id', (int) $data['driver_id'])->first();
        if (!$profile) {
            return response()->json(['ok' => false, 'message' => 'Profil chauffeur introuvable.'], 404);
        }

        $docs = is_array($profile->documents) ? $profile->documents : [];
        $key = (string) $data['document_key'];
        $existing = $docs[$key] ?? [];
        if (!is_array($existing)) {
            $existing = ['value' => $existing];
        }

        $existing['status'] = $data['status'];
        if (array_key_exists('expiry', $data)) {
            $existing['expiry'] = $data['expiry'];
        }
        $existing['reviewed_at'] = now()->toISOString();

        $docs[$key] = $existing;
        $profile->documents = $docs;
        $profile->save();

        return response()->json([
            'ok' => true,
            'message' => 'Statut du document mis à jour.',
            'driver_id' => $profile->user_id,
            'document_key' => $key,
            'document' => $existing,
        ]);
    }

    private function normalizeDriverDocuments($rawDocuments): array
    {
        if (!$rawDocuments) {
            return [];
        }

        $docs = is_array($rawDocuments) ? $rawDocuments : [];
        $normalized = [];

        // Supports both array format and object/map format.
        if (array_is_list($docs)) {
            foreach ($docs as $idx => $doc) {
                if (!is_array($doc)) {
                    continue;
                }

                $path = $doc['url'] ?? $doc['path'] ?? $doc['file'] ?? $doc['value'] ?? null;
                $label = $doc['name'] ?? $doc['label'] ?? ('Document ' . ($idx + 1));
                $status = $doc['status'] ?? (!empty($path) ? 'submitted' : 'missing');

                $normalized[] = [
                    'key' => 'doc_' . ($idx + 1),
                    'label' => (string) $label,
                    'status' => (string) $status,
                    'expiry' => $doc['expiry'] ?? null,
                    'raw_value' => $path,
                    'file_url' => $this->toPublicStorageUrl($path),
                ];
            }

            return $normalized;
        }

        foreach ($docs as $key => $value) {
            if (is_array($value)) {
                $path = $value['url'] ?? $value['path'] ?? $value['file'] ?? $value['value'] ?? null;
                $status = $value['status'] ?? (!empty($path) ? 'submitted' : 'missing');
                $expiry = $value['expiry'] ?? null;
                $label = $value['name'] ?? $value['label'] ?? $key;
            } else {
                $path = $value;
                $status = !empty($path) ? 'submitted' : 'missing';
                $expiry = null;
                $label = $key;
            }

            $normalized[] = [
                'key' => (string) $key,
                'label' => ucwords(str_replace(['_', '-'], ' ', (string) $label)),
                'status' => (string) $status,
                'expiry' => $expiry,
                'raw_value' => $path,
                'file_url' => $this->toPublicStorageUrl($path),
            ];
        }

        return $normalized;
    }

    private function toPublicStorageUrl($path): ?string
    {
        if (!$path || !is_string($path)) {
            return null;
        }

        $p = trim($path);
        if ($p === '') {
            return null;
        }

        if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
            return $p;
        }

        $cleaned = preg_replace('#^/?(api/)?storage/#', '', $p);
        return url('/api/storage/' . ltrim((string) $cleaned, '/'));
    }

    public function logs(Request $request)
    {
        $path = storage_path('logs/laravel.log');

        if (!File::exists($path)) {
            // Try to find the most recent daily log if it exists
            $files = File::glob(storage_path('logs/laravel-*.log'));
            if (!empty($files)) {
                rsort($files); // Get the newest one
                $path = $files[0];
            } else {
                return response()->json(['content' => 'Aucun fichier de log trouvé dans storage/logs/.']);
            }
        }

        // Read last 200 lines to avoid memory issues
        $content = $this->tailCustom($path, 200);

        return response()->json([
            'content' => $content,
            'file' => basename($path)
        ]);
    }


    /**
     * Efficiently read the end of a file
     */
    private function tailCustom($filepath, $lines = 100, $adaptive = true)
    {
        $f = @fopen($filepath, "rb");
        if ($f === false)
            return false;

        if (!$adaptive)
            $buffer = 4096;
        else
            $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));

        fseek($f, -1, SEEK_END);
        if (fread($f, 1) != "\n")
            $lines -= 1;

        $output = '';
        $chunk = '';

        while (ftell($f) > 0 && $lines >= 0) {
            $seek = min(ftell($f), $buffer);
            fseek($f, -$seek, SEEK_CUR);
            $chunk = fread($f, $seek);
            $output = $chunk . $output;
            fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);

            $lines -= substr_count($chunk, "\n");
        }

        // If we read too many lines, trim the beginning
        // (This is a simplified tail, sufficient for logs)
        $split = explode("\n", $output);
        if (count($split) > $lines) {
            $split = array_slice($split, -$lines);
        }

        fclose($f);
        return implode("\n", $split);
    }

    /**
     * Reset all application data for production deployment.
     * POST /api/admin/dev/reset-data
     * 
     * ⚠️ DANGER: This will delete all rides, transactions, and reset wallets!
     */
    public function resetData(Request $request)
    {
        if ($blocked = $this->ensureDangerousDevToolsAllowed()) {
            return $blocked;
        }

        $data = $request->validate([
            'confirm' => ['required', 'string', 'in:RESET_ALL_DATA'],
        ]);

        if ($data['confirm'] !== 'RESET_ALL_DATA') {
            return response()->json(['message' => 'Confirmation invalide'], 422);
        }

        // Expanded list of tables to clear
        $tables = [
            'wallet_transactions',
            'rides',
            'notifications',
            'otp_requests',
            'ratings',
            'geocoding_logs',
            'payments',
            'analytics_reconnections',
            'app_metrics',
            'driver_rewards',
            'driver_payouts', // Just in case it exists
            'addresses',      // Clear addresses if they are ride-related
            'stops',          // If these are user-created/volatile
            'lines',          // If these are user-created/volatile
            'line_stops',     // If these are user-created/volatile
        ];

        \DB::beginTransaction();
        try {
            // Count before deletion to report
            $counts = [];
            foreach ($tables as $table) {
                try {
                    if (\Schema::hasTable($table)) {
                        $counts[$table] = \DB::table($table)->count();
                    }
                } catch (\Exception $e) {
                    // Ignore count errors
                }
            }

            // Disable foreign key checks
            \DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($tables as $table) {
                try {
                    if (\Schema::hasTable($table)) {
                        \DB::table($table)->delete();
                        // Removing ALTER TABLE AUTO_INCREMENT as it might require extra privileges
                    }
                } catch (\Exception $e) {
                    \Log::warning("Could not clear table $table", ['error' => $e->getMessage()]);
                    // Continue to next table
                }
            }

            // Re-enable foreign key checks
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // Reset all wallet balances to 0
            try {
                if (\Schema::hasTable('wallets')) {
                    \DB::table('wallets')->update(['balance' => 0]);
                }
            } catch (\Exception $e) {
                \Log::warning("Could not reset wallet balances", ['error' => $e->getMessage()]);
            }

            // Clear the log file if reachable
            try {
                $logPath = storage_path('logs/laravel.log');
                if (file_exists($logPath) && is_writable($logPath)) {
                    file_put_contents($logPath, '');
                }
            } catch (\Exception $e) {
                // Ignore log clear errors
            }

            \DB::commit();

            return response()->json([
                'ok' => true,
                'message' => 'Toutes les données ont été réinitialisées avec succès.',
                'deleted' => $counts,
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();

            return response()->json([
                'message' => 'Erreur lors de la réinitialisation (BD)',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTrace() : null
            ], 500);
        }
    }

    /**
     * Purge stats only (rides, transactions, ratings) without deleting users.
     * POST /api/admin/dev/purge-stats
     */
    public function purgeStats(Request $request)
    {
        if ($blocked = $this->ensureDangerousDevToolsAllowed()) {
            return $blocked;
        }

        $data = $request->validate([
            'confirm' => ['required', 'string', 'in:PURGE_STATS_ONLY'],
        ]);

        $tables = [
            'wallet_transactions',
            'rides',
            'ratings',
            'analytics_reconnections',
            'app_metrics',
            'notifications',
        ];

        \DB::beginTransaction();
        try {
            \DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($tables as $table) {
                if (\Schema::hasTable($table)) {
                    \DB::table($table)->delete();
                }
            }

            \DB::statement('SET FOREIGN_KEY_CHECKS=1');

            if (\Schema::hasTable('wallets')) {
                \DB::table('wallets')->update(['balance' => 0]);
            }

            \DB::commit();

            return response()->json([
                'ok' => true,
                'message' => 'Les statistiques et transactions ont été purgées avec succès.',
            ]);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'message' => 'Erreur lors de la purge',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear all application cache.
     * POST /api/admin/dev/clear-cache
     */
    public function clearCache()
    {
        if ($blocked = $this->ensureDangerousDevToolsAllowed()) {
            return $blocked;
        }

        try {
            \Illuminate\Support\Facades\Cache::flush();
            return response()->json([
                'ok' => true,
                'message' => 'Le cache de l\'application a été vidé avec succès.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors du vidage du cache',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm a ride payment in sandbox/dev mode.
     * POST /api/admin/dev/rides/confirm-payment
     */
    public function confirmRidePayment(Request $request)
    {
        $data = $request->validate([
            'ride_id' => ['required', 'integer', 'exists:rides,id'],
        ]);

        $rideId = (int) $data['ride_id'];

        try {
            $ride = null;

            \DB::transaction(function () use ($rideId, &$ride) {
                /** @var Ride|null $ride */
                $ride = Ride::query()->lockForUpdate()->find($rideId);
                if (!$ride) {
                    throw new \RuntimeException('Course introuvable.');
                }

                // Idempotent: already confirmed
                if ($ride->payment_status === 'completed') {
                    return;
                }

                // Best effort: create a succeeded payment row if absent
                if (\Schema::hasTable('payments')) {
                    $hasSucceeded = \DB::table('payments')
                        ->where('ride_id', $ride->id)
                        ->where('status', 'succeeded')
                        ->exists();

                    if (!$hasSucceeded) {
                        $pm = (string) ($ride->payment_method ?? 'mobile_money');
                        if (!in_array($pm, ['mobile_money', 'card', 'qr', 'wallet', 'cash'], true)) {
                            $pm = 'mobile_money';
                        }

                        \DB::table('payments')->insert([
                            'ride_id' => $ride->id,
                            'user_id' => $ride->rider_id,
                            'amount' => (int) ($ride->fare_amount ?? 0),
                            'currency' => $ride->currency ?? 'XOF',
                            'method' => $pm,
                            'status' => 'succeeded',
                            'meta' => json_encode(['source' => 'developer_dashboard_sandbox_confirm']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $ride->payment_status = 'completed';
                $ride->save();
            });

            $fresh = Ride::find($rideId);
            if ($fresh) {
                rescue(fn () => broadcast(new PaymentConfirmed($fresh)));
            }

            return response()->json([
                'ok' => true,
                'message' => 'Paiement de la course confirmé avec succès.',
                'ride_id' => $rideId,
                'payment_status' => $fresh?->payment_status ?? 'completed',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Erreur lors de la confirmation du paiement.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

