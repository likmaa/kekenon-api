<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app' => ['required', 'string', 'in:passenger,driver'],
            'version' => ['required', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'in:ios,android,web'],
        ]);

        $cfg = config('mobile_apps.apps.'.$data['app']);
        if (! is_array($cfg)) {
            return response()->json(['error' => 'Unknown app'], 400);
        }

        $client = $this->normalizeVersion($data['version']);
        $min = $this->normalizeVersion((string) ($cfg['min_version'] ?? '0.0.0'));
        $latest = $this->normalizeVersion((string) ($cfg['latest_version'] ?? $min));

        $forceUpdate = version_compare($client, $min, '<');
        $belowLatest = version_compare($client, $latest, '<');
        $updateRecommended = $belowLatest && ! $forceUpdate;

        $platform = $data['platform'] ?? 'android';
        if (! in_array($platform, ['ios', 'android'], true)) {
            $platform = 'android';
        }

        $storeUrl = $this->resolveStoreUrl($cfg, $platform);

        return response()->json([
            'force_update' => $forceUpdate,
            'update_recommended' => $updateRecommended,
            'min_version' => $min,
            'latest_version' => $latest,
            'client_version' => $client,
            'message' => $forceUpdate
                ? 'Cette version n\'est plus prise en charge. Merci de mettre à jour l\'application.'
                : ($updateRecommended ? 'Une nouvelle version est disponible avec des correctifs et des améliorations.' : null),
            'store_url' => $storeUrl,
        ]);
    }

    private function normalizeVersion(string $v): string
    {
        return ltrim(trim($v), 'vV');
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function resolveStoreUrl(array $cfg, string $platform): ?string
    {
        if ($platform === 'ios') {
            $url = trim((string) ($cfg['ios_store_url'] ?? ''));

            return $url !== '' ? $url : null;
        }

        $url = trim((string) ($cfg['android_store_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $pkg = trim((string) ($cfg['android_package'] ?? ''));
        if ($pkg !== '') {
            return 'https://play.google.com/store/apps/details?id='.rawurlencode($pkg);
        }

        return null;
    }
}
