<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Intégration PawaPay (Deposits API v2) — encaissement Mobile Money.
 *
 * Contrairement à un checkout hébergé, PawaPay pousse une invite directement
 * sur le téléphone du client : on envoie le numéro MoMo + l'opérateur, le client
 * valide sur son mobile, et PawaPay confirme via callback (ou via relecture du
 * statut avec getDeposit()).
 *
 * Doc : https://docs.pawapay.io/v2/api-reference/deposits/initiate-deposit
 */
class PawaPayService
{
    protected string $baseUrl;

    protected string $apiToken;

    protected bool $sandbox;

    public function __construct()
    {
        $this->sandbox = (bool) config('services.pawapay.sandbox', true);
        // NB : ?: (et non le défaut de config()) car PAWAPAY_BASE_URL vaut souvent null/"".
        $configured = (string) (config('services.pawapay.base_url') ?? '');
        $this->baseUrl = rtrim(
            $configured !== '' ? $configured : ($this->sandbox ? 'https://api.sandbox.pawapay.io' : 'https://api.pawapay.io'),
            '/'
        );
        $this->apiToken = (string) config('services.pawapay.api_token', '');
    }

    protected function assertConfigured(): void
    {
        if ($this->apiToken === '') {
            throw new \RuntimeException(
                'PawaPay : renseignez PAWAPAY_API_TOKEN dans .env (jeton généré depuis le tableau de bord PawaPay).'
            );
        }
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /** Génère un identifiant de dépôt (UUIDv4) attendu par PawaPay. */
    public function newDepositId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Normalise un numéro béninois au format MSISDN attendu par PawaPay
     * (indicatif pays sans « + », ex. 22961234567).
     */
    public function normalizeMsisdn(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        // Retire un éventuel préfixe international déjà présent.
        if (str_starts_with($digits, '00229')) {
            $digits = substr($digits, 2);
        }
        if (! str_starts_with($digits, '229')) {
            $digits = '229' . ltrim($digits, '0');
        }

        return $digits;
    }

    /**
     * Initie un dépôt (le client paie via Mobile Money).
     *
     * @param  array{depositId?:string,amount:int,currency?:string,phoneNumber:string,provider:string,customerMessage?:string,clientReferenceId?:string,metadata?:array<int,array<string,mixed>>}  $params
     * @return array<string, mixed> Réponse PawaPay (depositId, status, created, failureReason)
     */
    public function createDeposit(array $params): array
    {
        $this->assertConfigured();

        $depositId = $params['depositId'] ?? $this->newDepositId();

        $body = [
            'depositId' => $depositId,
            'payer' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $this->normalizeMsisdn((string) $params['phoneNumber']),
                    'provider' => (string) $params['provider'],
                ],
            ],
            // PawaPay attend un montant en chaîne, sans décimales pour le XOF.
            'amount' => (string) (int) $params['amount'],
            'currency' => (string) ($params['currency'] ?? 'XOF'),
        ];

        if (! empty($params['clientReferenceId'])) {
            $body['clientReferenceId'] = (string) $params['clientReferenceId'];
        }
        if (! empty($params['customerMessage'])) {
            // Contrainte PawaPay : 4 à 22 caractères.
            $body['customerMessage'] = Str::limit((string) $params['customerMessage'], 22, '');
        }
        if (! empty($params['metadata']) && is_array($params['metadata'])) {
            $body['metadata'] = array_values($params['metadata']);
        }

        $response = Http::withHeaders($this->headers())
            ->post($this->baseUrl . '/v2/deposits', $body);

        $data = $this->unwrap($response, 'initiation de dépôt');
        // On renvoie toujours le depositId même si PawaPay ne le réécho pas.
        $data['depositId'] = $data['depositId'] ?? $depositId;

        return $data;
    }

    /**
     * Relit le statut réel d'un dépôt côté PawaPay (source de vérité).
     *
     * @return array<string, mixed>|null Objet dépôt (status COMPLETED/FAILED/…) ou null si introuvable.
     */
    public function getDeposit(string $depositId): ?array
    {
        $this->assertConfigured();

        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl . '/v2/deposits/' . $depositId);

        if (! $response->successful()) {
            Log::error('PawaPay getDeposit failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'depositId' => $depositId,
            ]);

            return null;
        }

        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        // Réponse v2 : { status: FOUND|NOT_FOUND, data: {...} }
        if (($body['status'] ?? null) === 'FOUND' && is_array($body['data'] ?? null)) {
            return $body['data'];
        }
        if (($body['status'] ?? null) === 'NOT_FOUND') {
            return null;
        }

        // Tolérance : certaines réponses renvoient directement l'objet dépôt.
        return $body['depositId'] ?? null ? $body : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function unwrap(Response $response, string $context): array
    {
        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException("PawaPay {$context} : réponse JSON invalide.");
        }

        if (! $response->successful()) {
            Log::error("PawaPay {$context} failed", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $msg = $body['failureReason']['failureMessage'] ?? $body['message'] ?? $response->body();
            $detail = is_string($msg) ? $msg : json_encode($msg);

            throw new \RuntimeException('PawaPay : ' . $detail);
        }

        // Un dépôt REJECTED renvoie un HTTP 200 mais un statut d'échec.
        if (($body['status'] ?? null) === 'REJECTED') {
            $reason = $body['failureReason']['failureMessage'] ?? 'dépôt rejeté par PawaPay.';
            throw new \RuntimeException('PawaPay : ' . (is_string($reason) ? $reason : 'dépôt rejeté.'));
        }

        return $body;
    }
}
