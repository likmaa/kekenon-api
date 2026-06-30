<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class MobileLogController extends Controller
{
    /**
     * Store logs from mobile apps
     * POST /api/analytics/log
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'app' => 'required|string|in:passenger,driver',
            'level' => 'required|string|in:info,warning,error,debug',
            'message' => 'required|string',
            'context' => 'nullable|array',
            'user_id' => 'nullable|integer',
            'device_info' => 'nullable|array',
        ]);

        $logEntry = sprintf(
            "[%s] %s.%s: %s %s [User: %s] [Device: %s]\n",
            now()->toDateTimeString(),
            strtoupper($data['app']),
            strtoupper($data['level']),
            $data['message'],
            json_encode($data['context'] ?? []),
            $data['user_id'] ?? 'Guest',
            json_encode($data['device_info'] ?? [])
        );

        $path = storage_path('logs/mobile_errors.log');

        try {
            File::append($path, $logEntry);
        } catch (\Exception $e) {
            // Fallback to standard laravel log if we can't write to custom log
            Log::channel('single')->error("Failed to write to mobile_errors.log: " . $e->getMessage());
            Log::channel('single')->info("Mobile log: " . $logEntry);
        }

        return response()->json(['status' => 'logged']);
    }

    /**
     * Get mobile logs for admin dashboard
     * GET /api/admin/dev/mobile-logs
     */
    public function index()
    {
        $path = storage_path('logs/mobile_errors.log');

        if (!File::exists($path)) {
            return response()->json(['content' => 'Aucun log mobile disponible.']);
        }

        // Read last 200 lines
        $content = $this->tailCustom($path, 200);

        return response()->json([
            'content' => $content,
            'file' => 'mobile_errors.log'
        ]);
    }

    /**
     * Clear mobile logs
     * POST /api/admin/dev/mobile-logs/clear
     */
    public function clear()
    {
        $path = storage_path('logs/mobile_errors.log');

        if (File::exists($path)) {
            File::put($path, '');
        }

        return response()->json(['message' => 'Logs mobiles effacés.']);
    }

    /**
     * Efficiently read the end of a file (copied from DeveloperController)
     */
    private function tailCustom($filepath, $lines = 100)
    {
        $f = @fopen($filepath, "rb");
        if ($f === false)
            return false;

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

        $split = explode("\n", $output);
        if (count($split) > $lines) {
            $split = array_slice($split, -$lines);
        }

        fclose($f);
        return implode("\n", $split);
    }
}
