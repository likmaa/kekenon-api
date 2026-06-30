<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeniusPayService
{
    protected string $baseUrl;

    protected string $publicKey;

    protected string $secretKey;

    protected string $webhookSecret;

    protected bool $sandbox;

    public function __construct()
    {
        $this->baseUrl = 'https://pay.genius.ci/api/v1/merchant';
        $this->publicKey = (string) config('services.geniuspay.public_key', '');
        $this->secretKey = (string) config('services.geniuspay.secret_key', '');
        $legacy = (string) config('services.geniuspay.api_key', '');
        if ($legacy !== '') {
            if (str_starts_with($legacy, 'pk_') && $this->publicKey === '') {
                $this->publicKey = $legacy;
            }
            if (str_starts_with($legacy, 'sk_') && $this->secretKey === '') {
                $this->secretKey = $legacy;
            }
        }
        $this->webhookSecret = (string) config('services.geniuspay.webhook_secret', '');
        $this->sandbox = (bool) config('services.geniuspay.sandbox', true);
    }

    protected function assertMerchantKeysConfigured(): void
    {
        if ($this->publicKey === '' || $this->secretKey === '') {
            throw new \RuntimeException(
                'GeniusPay : renseignez GENIUSPAY_PUBLIC_KEY (pk_…) et GENIUSPAY_SECRET_KEY (sk_…) dans .env. ' .
                'Documentation : https://pay.genius.ci/docs/api'
            );
        }
    }

    /**
     * @return array<string, mixed> Objet paiement (id, reference, checkout_url, …) extrait de la clé « data ».
     */
    protected function unwrapMerchantResponse(Response $response, string $context): array
    {
        $body = $response->json();
        if (!is_array($body)) {
            throw new \RuntimeException("GeniusPay {$context} : réponse JSON invalide.");
        }

        if (!$response->successful() || empty($body['success'])) {
            Log::error("GeniusPay {$context} failed", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $msg = $body['message'] ?? $body['error'] ?? $response->body();
            $detail = is_string($msg) ? $msg : json_encode($msg);

            throw new \RuntimeException('GeniusPay : ' . $detail);
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException("GeniusPay {$context} : réponse sans bloc « data ».");
        }

        return $data;
    }

    /**
     * @return array<string, mixed> Données du paiement (id, reference, checkout_url, payment_url, …)
     */
    public function createPayment(array $params): array
    {
        $this->assertMerchantKeysConfigured();

        $response = Http::withHeaders([
            'X-API-Key' => $this->publicKey,
            'X-API-Secret' => $this->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->baseUrl . '/payments', $params);

        $logParams = $params;
        unset($logParams['customer']);

        if (!$response->successful()) {
            Log::error('GeniusPay payment creation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'params' => $logParams,
            ]);
        }

        return $this->unwrapMerchantResponse($response, 'création de paiement');
    }

    /**
     * @param  string  $paymentReference  Référence transaction (ex. MTX-…) ou identifiant attendu par l’API.
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentReference): array
    {
        $this->assertMerchantKeysConfigured();

        $response = Http::withHeaders([
            'X-API-Key' => $this->publicKey,
            'X-API-Secret' => $this->secretKey,
            'Accept' => 'application/json',
        ])->get($this->baseUrl . '/payments/' . $paymentReference);

        if (!$response->successful()) {
            Log::error('GeniusPay getPayment failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'reference' => $paymentReference,
            ]);
        }

        return $this->unwrapMerchantResponse($response, 'récupération de paiement');
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if ($this->webhookSecret === '') {
            return true;
        }

        $computed = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($computed, $signature);
    }
}
