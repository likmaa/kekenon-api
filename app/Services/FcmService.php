<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

class FcmService
{
    protected $projectId;

    protected $serviceAccountConfig;

    /** @var array{step: string, detail: string}|null */
    protected ?array $lastFcmTokenFailure = null;

    public function __construct()
    {
        $this->loadServiceAccountConfig();
    }

    /**
     * SEC-08 : ne jamais lire un JSON de compte de service depuis un chemin versionne.
     * Utiliser FIREBASE_SERVICE_ACCOUNT_JSON (JSON en une ligne ou base64) ou FIREBASE_SERVICE_ACCOUNT_PATH.
     */
    protected function loadServiceAccountConfig(): void
    {
        $this->serviceAccountConfig = null;
        $this->projectId = null;

        $fromEnv = null;
        $json = config('services.fcm.service_account_json');
        if (is_string($json)) {
            $raw = trim($json);
            if ($raw !== '') {
                $fromEnv = $this->parseServiceAccountRaw($raw);
            }
        }

        if ($this->serviceAccountLooksUsable($fromEnv)) {
            $this->serviceAccountConfig = $fromEnv;
            $this->projectId = $fromEnv['project_id'] ?? null;

            return;
        }

        if ($fromEnv !== null) {
            Log::warning('FCM: FIREBASE_SERVICE_ACCOUNT_JSON ignoré (JSON incomplet ou clé PEM invalide). Utilisation du fichier si défini.');
        }

        $path = config('services.fcm.service_account_path');
        if (! is_string($path)) {
            return;
        }

        $path = trim($path);
        if ($path === '') {
            return;
        }

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! is_readable($path)) {
            if (file_exists($path)) {
                $size = @filesize($path) ?: 0;
                Log::warning("FCM: compte de service présent mais illisible par PHP : {$path} (octets={$size}) — chmod/chown pour PHP-FPM (souvent www-data).");
            } else {
                Log::warning("FCM: compte de service absent : {$path} — vérifier le nom du fichier et FIREBASE_SERVICE_ACCOUNT_PATH.");
            }

            return;
        }

        $rawFile = (string) file_get_contents($path);
        $fromFile = json_decode($rawFile, true);
        $jsonError = json_last_error();

        if (! $this->serviceAccountLooksUsable($fromFile)) {
            $hint = $this->explainInvalidServiceAccount($fromFile, $jsonError, strlen($rawFile));
            Log::error("FCM: fichier compte de service rejeté ({$hint}) : {$path}");

            return;
        }

        $this->serviceAccountConfig = $fromFile;
        $this->projectId = $fromFile['project_id'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseServiceAccountRaw(string $raw): ?array
    {
        if (str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && str_starts_with(ltrim($decoded), '{')) {
            $inner = json_decode($decoded, true);

            return is_array($inner) ? $inner : null;
        }

        return null;
    }

    /**
     * Détaille pour les logs pourquoi le JSON n’est pas utilisable (sans secret).
     *
     * @param  array<string, mixed>|null  $config
     */
    protected function explainInvalidServiceAccount(?array $config, int $jsonError, int $rawByteLength): string
    {
        if ($jsonError !== JSON_ERROR_NONE) {
            return 'JSON : ' . json_last_error_msg();
        }

        if ($rawByteLength === 0) {
            return 'fichier vide';
        }

        if (! is_array($config)) {
            return 'JSON : document non objet (null ou scalaire)';
        }

        foreach (['project_id', 'client_email', 'private_key'] as $k) {
            if (! isset($config[$k]) || ! is_string($config[$k]) || trim($config[$k]) === '') {
                return "champ manquant ou vide : {$k}";
            }
        }

        if (($config['type'] ?? '') !== 'service_account') {
            return 'type inattendu (attendu service_account), reçu : ' . (is_scalar($config['type'] ?? null) ? (string) $config['type'] : 'non scalaire');
        }

        $pem = $this->normalizePrivateKeyPem($config['private_key']);
        if (! str_contains($pem, 'BEGIN') || ! str_contains($pem, 'PRIVATE KEY')) {
            return 'private_key sans bloc PEM BEGIN … PRIVATE KEY';
        }

        while (openssl_error_string() !== false) {
        }

        if (openssl_pkey_get_private($pem) !== false) {
            return 'raison non déterminée';
        }

        $sslErr = openssl_error_string() ?: 'clé privée refusée';
        if ($this->privateKeyLoadsWithPhpSecLib($pem)) {
            return 'raison non déterminée';
        }

        return sprintf(
            'OpenSSL : %s ; phpseclib : %s (PEM normalisé %d octets — si très petit, JSON tronqué ou mauvais fichier sur le serveur)',
            $sslErr,
            $this->phpSecLibPrivateKeyLoadFailureDetail($pem),
            strlen($pem)
        );
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    protected function serviceAccountLooksUsable(?array $config): bool
    {
        if (! is_array($config)) {
            return false;
        }

        $email = $config['client_email'] ?? null;
        $projectId = $config['project_id'] ?? null;
        $pem = $this->normalizePrivateKeyPem($config['private_key'] ?? null);

        if (! is_string($email) || $email === '' || ! is_string($projectId) || $projectId === '' || $pem === '') {
            return false;
        }

        if (! str_contains($pem, 'BEGIN') || ! str_contains($pem, 'PRIVATE KEY')) {
            return false;
        }

        while (openssl_error_string() !== false) {
        }

        if (openssl_pkey_get_private($pem) !== false) {
            return true;
        }

        return $this->privateKeyLoadsWithPhpSecLib($pem);
    }

    /**
     * Sur certaines images PHP Alpine, openssl_pkey_get_private échoue sur des PEM
     * PKCS#8 valides (DECODER routines::unsupported) ; phpseclib parse le DER lui-même.
     */
    protected function privateKeyLoadsWithPhpSecLib(string $pem): bool
    {
        try {
            $k = PublicKeyLoader::loadPrivateKey($pem);

            return $k instanceof RSA\PrivateKey;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Message d’exception phpseclib (sans clé) pour le diagnostic serveur.
     */
    protected function phpSecLibPrivateKeyLoadFailureDetail(string $pem): string
    {
        try {
            $k = PublicKeyLoader::loadPrivateKey($pem);

            return $k instanceof RSA\PrivateKey
                ? 'OK (incohérence avec serviceAccountLooksUsable)'
                : 'clé non RSA : ' . $k::class;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    protected function normalizePrivateKeyPem(mixed $key): string
    {
        if (! is_string($key) || $key === '') {
            return '';
        }

        $key = str_replace('\\n', "\n", $key);
        $key = str_replace(["\r\n", "\r"], "\n", $key);
        $key = preg_replace('/^\xEF\xBB\xBF/u', '', $key) ?? $key;
        $key = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $key) ?? $key;
        $key = trim($key);

        // OpenSSL 3 est strict : un PEM mal coupé (copier-coller, éditeur) peut provoquer
        // error:1E08010C:DECODER routines::unsupported. Reconstruire depuis le DER.
        foreach (['PRIVATE KEY', 'RSA PRIVATE KEY'] as $kind) {
            $begin = "-----BEGIN {$kind}-----";
            $end = "-----END {$kind}-----";
            if (! str_contains($key, $begin) || ! str_contains($key, $end)) {
                continue;
            }
            $start = strpos($key, $begin);
            $stop = strpos($key, $end, $start);
            if ($start === false || $stop === false) {
                continue;
            }
            $inner = substr($key, $start + strlen($begin), $stop - $start - strlen($begin));
            $b64 = preg_replace('/\s+/', '', $inner) ?? '';
            $der = base64_decode($b64, true);
            if ($der !== false && strlen($der) > 32) {
                $body = rtrim(chunk_split(base64_encode($der), 64, "\n"));

                return "{$begin}\n{$body}\n{$end}\n";
            }
        }

        return $key;
    }

    /**
     * Send a notification to a specific user.
     *
     * @param  bool  $dataOnly  Message data-only (sans bloc `notification`) : l'app affiche
     *                          elle-même via ses handlers FCM. Indispensable côté chauffeur où
     *                          l'auto-affichage Android est cassé (conflit expo-notifications /
     *                          react-native-firebase). Voir app chauffeur fcmDisplay.ts.
     */
    public function sendToUser(User $user, $title, $body, $data = [], bool $dataOnly = false)
    {
        $tokens = $user->fcmTokens()->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info("No FCM tokens found for user ID: {$user->id}");
            return false;
        }

        // L'app chauffeur affiche elle-même (auto-affichage Android cassé sur ce build) :
        // tout envoi vers un chauffeur passe en data-only, quel que soit l'appelant.
        $dataOnly = $dataOnly || ($user->role ?? null) === 'driver';

        return $this->sendToTokens($tokens, $title, $body, $data, $dataOnly);
    }

    /**
     * Canaux Android + sons (fichiers dans res/raw côté app, bundle iOS).
     * Aligné avec l’app passager expo-notifications (tic_ride, tic_wallet, …).
     */
    protected function resolveNotificationPresentationMeta(array $data): array
    {
        $type = strtolower((string) ($data['type'] ?? ''));

        $rideTypes = ['ride_accepted', 'driver_arrived', 'ride_started', 'ride_completed', 'ride_cancelled', 'new_ride'];
        if (in_array($type, $rideTypes, true)) {
            return [
                'android_channel' => 'tic_ride',
                'android_sound' => 'ride',
                'ios_sound' => 'ride.wav',
            ];
        }

        if (str_contains($type, 'wallet') || str_contains($type, 'topup') || $type === 'credit') {
            return [
                'android_channel' => 'tic_wallet',
                'android_sound' => 'wallet',
                'ios_sound' => 'wallet.wav',
            ];
        }

        if (in_array($type, ['promo', 'marketing', 'system'], true)) {
            return [
                'android_channel' => 'tic_promo',
                'android_sound' => 'promo',
                'ios_sound' => 'promo.wav',
            ];
        }

        return [
            'android_channel' => 'tic_default',
            'android_sound' => 'tic_default',
            'ios_sound' => 'tic_default.wav',
        ];
    }

    /**
     * Send a notification to multiple tokens using FCM V1.
     */
    public function sendToTokens(array $tokens, $title, $body, $data = [], bool $dataOnly = false)
    {
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            Log::error("FCM V1: Failed to obtain access token.");
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $successCount = 0;
        $meta = $this->resolveNotificationPresentationMeta($data);
        $tokenRows = FcmToken::query()
            ->whereIn('token', $tokens)
            ->get(['token', 'device_type', 'user_id'])
            ->keyBy('token');

        // On recopie toujours title/body + canal/son dans `data` : ainsi un client qui
        // affiche lui-même (handlers FCM, mode data-only) a tout sous la main, sans casser
        // les clients qui ignorent ces clés supplémentaires.
        $dataPayload = array_map('strval', array_merge($data, [
            'title' => (string) $title,
            'body' => (string) $body,
            'android_channel' => $meta['android_channel'],
            'android_sound' => $meta['android_sound'],
        ]));

        foreach ($tokens as $token) {
            $message = [
                'token' => $token,
                'data' => $dataPayload, // V1 requires data values to be strings
                'android' => [
                    'priority' => 'high',
                ],
            ];

            if ($dataOnly) {
                // Pas de bloc `notification` → réveille setBackgroundMessageHandler côté app,
                // qui construit la notif locale. iOS : content-available pour livrer le data.
                $message['apns'] = [
                    'headers' => [
                        'apns-priority' => '5',
                        'apns-push-type' => 'background',
                    ],
                    'payload' => [
                        'aps' => [
                            'content-available' => 1,
                        ],
                    ],
                ];
            } else {
                $message['notification'] = [
                    'title' => $title,
                    'body' => $body,
                ];
                $message['android']['notification'] = [
                    'channel_id' => $meta['android_channel'],
                    'sound' => $meta['android_sound'],
                ];
                // iOS : inclure explicitement `aps.alert` — un `aps` partiel (sound seul) peut empêcher
                // l’affichage de la bannière selon la fusion FCM → APNs.
                $message['apns'] = [
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'sound' => $meta['ios_sound'],
                        ],
                    ],
                ];
            }

            $payload = ['message' => $message];

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                $row = $tokenRows->get($token);
                $deviceType = $row?->device_type ?? 'unknown';
                $tokenPrefix = Str::limit($token, 24, '');

                if ($response->successful()) {
                    $successCount++;
                    Log::info("FCM V1: OK jeton ({$deviceType}) prefix={$tokenPrefix}");
                } else {
                    $responseJson = $response->json();
                    $drop = is_array($responseJson) && $this->shouldDropInvalidToken($responseJson);
                    if ($drop) {
                        $deleted = FcmToken::query()->where('token', $token)->delete();
                        $reason = $this->describeInvalidFcmTokenReason($responseJson);
                        Log::warning("FCM V1: token supprimé ({$reason}) device_type={$deviceType} prefix={$tokenPrefix}, rows={$deleted}");
                    } else {
                        Log::error("FCM V1 Error device_type={$deviceType} prefix={$tokenPrefix}: " . $response->body());
                    }
                }
            } catch (\Exception $e) {
                Log::error("FCM V1 Exception: " . $e->getMessage());
            }
        }

        Log::info("FCM V1: Successfully sent to {$successCount}/" . count($tokens) . " tokens.");
        return $successCount > 0;
    }

    public function getLoadedProjectId(): ?string
    {
        return $this->projectId;
    }

    /**
     * @return array{step: string, detail: string}|null
     */
    public function getLastFcmTokenFailure(): ?array
    {
        return $this->lastFcmTokenFailure;
    }

    /**
     * Indique si un compte de service est chargé et si un jeton OAuth peut être obtenu.
     */
    public function canAuthenticate(): bool
    {
        if (! is_array($this->serviceAccountConfig) || ! $this->projectId) {
            $this->lastFcmTokenFailure = [
                'step' => 'config',
                'detail' => 'Compte de service non chargé (fichier absent, illisible, JSON invalide, ou FIREBASE_SERVICE_ACCOUNT_JSON prioritaire et erroné).',
            ];

            return false;
        }

        return (bool) $this->getAccessToken();
    }

    protected function recordFcmTokenFailure(string $step, string $detail): void
    {
        $this->lastFcmTokenFailure = ['step' => $step, 'detail' => $detail];
    }

    protected function clearFcmTokenFailure(): void
    {
        $this->lastFcmTokenFailure = null;
    }

    /**
     * Détecte un token FCM définitivement invalide (à supprimer en base).
     * - INVALID_ARGUMENT + fieldViolations message.token (jeton mal formé / mauvais projet).
     * - NOT_FOUND : jeton désinscrit (UNREGISTERED), app réinstallée, MAJ iOS, etc.
     *
     * @param array<string, mixed> $responseJson
     */
    protected function shouldDropInvalidToken(array $responseJson): bool
    {
        $error = $responseJson['error'] ?? null;
        if (! is_array($error)) {
            return false;
        }

        $status = strtoupper((string) ($error['status'] ?? ''));

        if ($status === 'NOT_FOUND') {
            // Jeton révoqué / app réinstallée / rotation après MAJ — FCM renvoie souvent UNREGISTERED dans details.
            return true;
        }

        if ($status !== 'INVALID_ARGUMENT') {
            return false;
        }

        $details = $error['details'] ?? null;
        if (! is_array($details)) {
            return false;
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $violations = $detail['fieldViolations'] ?? null;
            if (! is_array($violations)) {
                continue;
            }
            foreach ($violations as $violation) {
                if (! is_array($violation)) {
                    continue;
                }
                $field = (string) ($violation['field'] ?? '');
                if ($field === 'message.token') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $responseJson
     */
    protected function describeInvalidFcmTokenReason(array $responseJson): string
    {
        $error = $responseJson['error'] ?? null;
        $status = is_array($error) ? strtoupper((string) ($error['status'] ?? '')) : '';

        if ($status === 'NOT_FOUND') {
            return 'NOT_FOUND/UNREGISTERED';
        }

        return 'INVALID_ARGUMENT';
    }

    /**
     * Generate Google OAuth2 Access Token manually using Service Account.
     */
    protected function getAccessToken()
    {
        $cached = Cache::get('fcm_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $this->clearFcmTokenFailure();

        if (! $this->serviceAccountConfig) {
            $this->recordFcmTokenFailure(
                'config',
                'Aucun compte de service chargé. Vider FIREBASE_SERVICE_ACCOUNT_JSON si doute, vérifier FIREBASE_SERVICE_ACCOUNT_PATH et les logs « FCM: ».'
            );

            return null;
        }

        $config = $this->serviceAccountConfig;
        $now = time();

        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = json_encode([
            'iss' => $config['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);

        $privateKeyPem = $this->normalizePrivateKeyPem($config['private_key'] ?? '');
        $dataToSign = $base64UrlHeader . '.' . $base64UrlPayload;
        $signature = $this->signJwtRs256($privateKeyPem, $dataToSign);
        if ($signature === null) {
            $this->recordFcmTokenFailure(
                'signature',
                'Signature JWT RS256 impossible (OpenSSL / phpseclib). Consulter les logs « FCM: » pour le détail.'
            );

            return null;
        }

        $base64UrlSignature = $this->base64UrlEncode($signature);

        $jwt = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;

        $response = Http::timeout(20)->asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            $snippet = Str::limit((string) $response->body(), 1200);
            $this->recordFcmTokenFailure(
                'oauth',
                'Réponse oauth2.googleapis.com HTTP ' . $response->status() . ' — ' . $snippet
            );
            Log::error('FCM: réponse OAuth sans access_token : ' . $response->body());

            return null;
        }

        Cache::put('fcm_access_token', $accessToken, 3500);

        return $accessToken;
    }

    protected function base64UrlEncode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Signature RS256 (PKCS#1 v1.5 + SHA-256) pour assertion JWT Google.
     * OpenSSL en premier ; repli phpseclib si le PEM est refusé (ex. PHP 8.4 + Alpine).
     */
    protected function signJwtRs256(string $privateKeyPem, string $dataToSign): ?string
    {
        while (openssl_error_string() !== false) {
        }

        $pkey = openssl_pkey_get_private($privateKeyPem);
        if ($pkey !== false) {
            $signature = '';
            if (openssl_sign($dataToSign, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
                return $signature;
            }
            Log::warning('FCM: openssl_sign a échoué, repli phpseclib.');
        } else {
            Log::info('FCM: openssl refuse le PEM PKCS#8, signature JWT via phpseclib.');
        }

        try {
            $key = PublicKeyLoader::loadPrivateKey($privateKeyPem);
            if (! $key instanceof RSA\PrivateKey) {
                Log::error('FCM: phpseclib — clé RSA privée attendue pour FCM.');

                return null;
            }
            $sig = $key->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->sign($dataToSign);

            return $sig !== '' ? $sig : null;
        } catch (\Throwable $e) {
            Log::error('FCM: phpseclib (signature JWT) : ' . $e->getMessage());

            return null;
        }
    }
}
