<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Quand Mapbox + Nominatim ne renvoient aucun résultat, propose de courtes requêtes texte
 * (synonymes, formulation « officielle », ajout de la ville) pour un second passage Nominatim.
 * Ne fournit jamais de coordonnées inventées : uniquement des chaînes de recherche.
 */
class GeminiGeocodingAssistant
{
    private const QUERIES_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'queries' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'minItems' => 1,
                'maxItems' => 4,
                'description' => 'Variantes courtes pour recherche Nominatim/OSM, sans coordonnées.',
            ],
        ],
        'required' => ['queries'],
    ];

    private const SYSTEM = <<<'TXT'
Tu aides le géocodage d'une application VTC au Bénin (et zones proches).

L'utilisateur a saisi ou dicté un lieu souvent informel : surnom de quartier, marché, carrefour, école, expression orale, faute d'orthographe, ou zone peu indexée dans OpenStreetMap.

Tâche : proposer 1 à 4 requêtes TRÈS COURTES (idéalement ≤ 80 caractères chacune) qui maximisent les chances de trouver le lieu dans Nominatim / OpenStreetMap.

Règles :
- Ajoute la ville (Cotonou, Porto-Novo, Parakou, Calavi, Abomey-Calavi, etc.) seulement si c'est cohérent avec la requête ou la position approximative fournie.
- Corrige légèrement les fautes évidentes sans changer le lieu voulu.
- Propose des synonymes ou formulations plus « carte » (ex. marché + nom connu) si pertinent.
- INTERDIT d'inventer un lieu différent de l'intention probable.
- INTERDIT de renvoyer des coordonnées GPS ou du JSON autre que le schéma.
- Si la requête est totalement vide ou absurde : une seule entrée dans "queries" qui reprend la requête nettoyée telle quelle.
TXT;

    /**
     * @return list<string>
     */
    public function suggestNominatimQueries(string $userQuery, ?float $lat, ?float $lon, string $language): array
    {
        $apiKey = config('services.gemini.key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return [];
        }

        $geoModel = config('services.gemini.geo_model');
        $model = (is_string($geoModel) && trim($geoModel) !== '')
            ? trim($geoModel)
            : (string) config('services.gemini.voice_model', 'gemini-2.5-flash');
        $model = preg_match('/^[a-zA-Z0-9.\-]+$/', $model) ? $model : 'gemini-2.5-flash';

        $ctx = '';
        if ($lat !== null && $lon !== null) {
            $ctx = sprintf('Position approximative du passager : lat %.4f, lon %.4f. ', $lat, $lon);
        }

        $userText = $ctx."Requête utilisateur : « {$userQuery} »\nLangue préférée pour les libellés : {$language}.\nRéponds uniquement avec le JSON du schéma.";

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model
        );

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => self::SYSTEM]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userText]],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.15,
                'maxOutputTokens' => 256,
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => self::QUERIES_SCHEMA,
            ],
        ];

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($url, $payload);

            if (! $response->ok() && $response->status() === 400) {
                $payloadLoose = [
                    'systemInstruction' => [
                        'parts' => [[
                            'text' => self::SYSTEM."\n\nRéponds par une seule ligne JSON brut : {\"queries\":[\"...\",\"...\"]}\n(maximum 4 chaînes courtes).",
                        ]],
                    ],
                    'contents' => $payload['contents'],
                    'generationConfig' => [
                        'temperature' => 0.15,
                        'maxOutputTokens' => 256,
                    ],
                ];
                $response = Http::timeout(25)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post($url, $payloadLoose);
            }

            if (! $response->ok()) {
                Log::warning('GeminiGeocodingAssistant: API non OK', ['status' => $response->status()]);

                return [];
            }

            $raw = $this->extractTextFromGeminiResponse($response->json());
            if ($raw === null || $raw === '') {
                return [];
            }

            $t = trim($raw);
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $t, $m)) {
                $t = trim($m[1]);
            }

            $decoded = json_decode($t, true);
            if (! is_array($decoded) || ! isset($decoded['queries']) || ! is_array($decoded['queries'])) {
                return [];
            }

            $out = [];
            foreach ($decoded['queries'] as $q) {
                if (! is_string($q)) {
                    continue;
                }
                $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
                if ($q === '' || mb_strlen($q) > 160) {
                    continue;
                }
                if (! in_array($q, $out, true)) {
                    $out[] = $q;
                }
                if (count($out) >= 4) {
                    break;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractTextFromGeminiResponse(array $data): ?string
    {
        $candidates = $data['candidates'] ?? null;
        if (! is_array($candidates) || $candidates === []) {
            return null;
        }

        $first = $candidates[0];
        if (! is_array($first)) {
            return null;
        }

        $finish = $first['finishReason'] ?? $first['finish_reason'] ?? null;
        if (is_string($finish) && strtoupper($finish) === 'SAFETY') {
            return null;
        }

        $content = $first['content'] ?? null;
        if (! is_array($content)) {
            return null;
        }

        $parts = $content['parts'] ?? [];
        if (! is_array($parts)) {
            return null;
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $chunks[] = $part['text'];
            }
        }

        if ($chunks === []) {
            return null;
        }

        return trim(implode("\n", $chunks));
    }
}
