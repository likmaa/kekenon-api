<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class VoiceController extends Controller
{
    private const MAX_TRANSCRIPTION_LENGTH = 500;

    /** Schéma JSON pour extraction lieu (évite les réponses libres type « Ganhi » inventées). */
    private const PLACE_RESPONSE_JSON_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'verbatim' => [
                'type' => 'string',
                'description' => 'Transcription factuelle de ce qui est audible (langue d\'origine), sans ajouter de lieux non dits.',
            ],
            'place_query' => [
                'type' => 'string',
                'description' => 'Une ligne pour recherche carte : uniquement toponymes prononcés. Chaîne vide si aucun lieu clair.',
            ],
        ],
        'required' => ['verbatim', 'place_query'],
    ];

    private const SYSTEM_PLACE_JSON = <<<'TXT'
Tu analyses l'audio d'un passager dans une application de transport (VTC / livraison) au Bénin. L'audio peut être en Français, mais aussi souvent en langues locales (Fon, Goun, Yoruba, Mina, etc.), avec parfois du bruit de rue. Même si tu ne maîtrises pas couramment ces langues locales, concentre-toi sur la détection phonétique des noms de lieux.

Tu dois répondre UNIQUEMENT via le JSON imposé par le schéma (pas de markdown, pas de texte avant ou après).

Champs :
- "verbatim" : ce que tu entends réellement, resté proche de l'oral (mots entendus, même courts). Si presque inaudible, mets ce que tu captes sans inventer de phrase complète avec adresse. Tu peux utiliser "(inaudible)" si vraiment rien d'exploitable.
- "place_query" : une seule ligne utilisable dans une barre de recherche carte — UNIQUEMENT des lieux ou repères géographiques que la personne a explicitement prononcés (quartier, rue, carrefour, ville, nom d'édifice, marché, etc.). Si plusieurs arrêts demandés, sépare par ", ".

Règles strictes :
- Ne JAMAIS remplir "place_query" avec un lieu plausible ou fréquent si la personne ne l'a pas dit (pas de substitution, pas de « quartier par défaut », pas d'approximation par une zone connue).
- Ne JAMAIS inventer de noms de villes ou quartiers sous prétexte d'aider : c'est une erreur grave.
- Si l'audio ne contient aucun toponyme clair : mets "place_query" à une chaîne vide "" exactement.
- N'inclus pas de guillemets typographiques inutiles dans les valeurs JSON ; échappe correctement les caractères spéciaux JSON.

Tu recevras l'audio en pièce jointe dans le message utilisateur.
TXT;

    /** Même logique sans responseJsonSchema (anciens modèles / erreur 400). */
    private const SYSTEM_PLACE_JSON_FALLBACK = <<<'TXT'
Même tâche que précédemment : audio passager VTC au Bénin (Français, Fon, Goun, Yoruba), extraction des lieux pour la carte.

Réponds par UNE SEULE ligne, sans markdown : un objet JSON brut exactement de la forme :
{"verbatim":"...","place_query":"..."}

Règles identiques : verbatim fidèle ; place_query uniquement lieux prononcés ou "" ; aucun lieu inventé.
TXT;

    /** Transcription utile pour le chauffeur (consigne / contexte). */
    private const SYSTEM_DRIVER_MESSAGE = <<<'TXT'
Tu transcris l'audio d'un passager VTC au Bénin (l'audio peut être en Français, Fon, Goun, ou Yoruba) pour qu'un chauffeur le lise sur son téléphone.
Règles :
- Texte clair en français (corrige légèrement l'oral si besoin pour la lisibilité, sans inventer de faits).
- Une ou deux phrases maximum si c'est court ; jusqu'à 400 caractères si le passager donne plusieurs détails (ex. couleur de portail, étage, personne à contacter).
- Pas de guillemets englobants, pas de "Transcription :", pas de liste à puces.
- Si l'audio est vide ou inaudible : __EMPTY__
TXT;

    public function search(Request $request)
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:10240'], // 10 Mo max
            'purpose' => ['nullable', 'string', 'in:place,driver_message'],
        ]);

        /** @var UploadedFile|null $file */
        $file = $request->file('audio');
        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => 'Fichier audio invalide'], 422);
        }

        $purpose = $request->input('purpose', 'place');
        $purpose = in_array($purpose, ['place', 'driver_message'], true) ? $purpose : 'place';

        $apiKey = config('services.gemini.key');
        if (! $apiKey || ! is_string($apiKey) || trim($apiKey) === '') {
            return response()->json([
                'error' => 'Transcription indisponible',
                'message' => 'Configurer GEMINI_API_KEY dans le fichier .env du backend, puis redémarrer PHP (php artisan config:clear). Aucune donnée fictive n\'est renvoyée.',
                'code' => 'GEMINI_NOT_CONFIGURED',
            ], 503);
        }

        try {
            $binary = file_get_contents($file->getRealPath());
            if ($binary === false || $binary === '') {
                return response()->json(['error' => 'Impossible de lire le fichier audio'], 422);
            }

            $base64 = base64_encode($binary);
            $mimeType = $this->resolveAudioMimeType($file);

            $model = config('services.gemini.voice_model', 'gemini-2.5-flash');
            $model = preg_match('/^[a-zA-Z0-9.\-]+$/', (string) $model) ? $model : 'gemini-2.5-flash';

            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                $model
            );

            if ($purpose === 'driver_message') {
                return $this->handleDriverMessage($url, $apiKey, $base64, $mimeType);
            }

            return $this->handlePlaceExtraction($url, $apiKey, $base64, $mimeType);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Erreur de transcription',
                'message' => config('app.debug') ? $e->getMessage() : 'Erreur serveur',
            ], 500);
        }
    }

    private function handleDriverMessage(string $url, string $apiKey, string $base64, string $mimeType): \Illuminate\Http\JsonResponse
    {
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => self::SYSTEM_DRIVER_MESSAGE]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => 'Transcris l\'audio pour le chauffeur selon les règles du message système.',
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 512,
            ],
        ];

        $response = Http::timeout(60)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ])
            ->post($url, $payload);

        if (! $response->ok()) {
            return $this->geminiErrorResponse($response);
        }

        $data = $response->json();
        $raw = $this->extractTextFromGeminiResponse($data);

        if ($raw === null) {
            $blockReason = $data['promptFeedback']['blockReason'] ?? null;

            return response()->json([
                'error' => 'Aucun texte renvoyé par le modèle',
                'block_reason' => $blockReason,
            ], 422);
        }

        $text = $this->sanitizeTranscription($raw, 2000, false);

        if ($text === '' || strtoupper($text) === '__EMPTY__') {
            return response()->json([
                'error' => 'Parole non comprise ou audio trop court',
            ], 422);
        }

        return response()->json([
            'text' => $text,
            'purpose' => 'driver_message',
        ]);
    }

    private function handlePlaceExtraction(string $url, string $apiKey, string $base64, string $mimeType): \Illuminate\Http\JsonResponse
    {
        $audioParts = [
            [
                'text' => 'Analyse uniquement l\'audio ci-joint et produis le JSON demandé (champs verbatim et place_query).',
            ],
            [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $base64,
                ],
            ],
        ];

        $payloadStructured = [
            'systemInstruction' => [
                'parts' => [['text' => self::SYSTEM_PLACE_JSON . $this->getLocalContext()]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $audioParts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.05,
                'maxOutputTokens' => 512,
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => self::PLACE_RESPONSE_JSON_SCHEMA,
            ],
        ];

        $response = Http::timeout(60)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ])
            ->post($url, $payloadStructured);

        if (! $response->ok() && $response->status() === 400) {
            $payloadLoose = [
                'systemInstruction' => [
                    'parts' => [['text' => self::SYSTEM_PLACE_JSON_FALLBACK . $this->getLocalContext()]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => $audioParts,
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.05,
                    'maxOutputTokens' => 512,
                ],
            ];
            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($url, $payloadLoose);
        }

        if (! $response->ok()) {
            return $this->geminiErrorResponse($response);
        }

        $data = $response->json();
        $raw = $this->extractTextFromGeminiResponse($data);

        if ($raw === null) {
            $blockReason = $data['promptFeedback']['blockReason'] ?? null;

            return response()->json([
                'error' => 'Aucun texte renvoyé par le modèle',
                'block_reason' => $blockReason,
            ], 422);
        }

        $parsed = $this->parsePlaceJsonPayload($raw);
        $verbatim = $this->sanitizeTranscription($parsed['verbatim'] ?? '', 800, true);
        $placeQuery = $this->sanitizeTranscription($parsed['place_query'] ?? '', self::MAX_TRANSCRIPTION_LENGTH, true);

        if (strtoupper($placeQuery) === '__EMPTY__') {
            $placeQuery = '';
        }

        if ($placeQuery === '') {
            return response()->json([
                'error' => 'Aucun lieu identifiable dans l’audio pour la recherche carte',
                'verbatim' => $verbatim,
            ], 422);
        }

        return response()->json([
            'text' => $placeQuery,
            'verbatim' => $verbatim,
            'purpose' => 'place',
        ]);
    }

    /**
     * @return array{verbatim: string, place_query: string}
     */
    private function parsePlaceJsonPayload(string $raw): array
    {
        $t = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $t, $m)) {
            $t = trim($m[1]);
        }

        $decoded = json_decode($t, true);
        if (is_array($decoded)) {
            return [
                'verbatim' => isset($decoded['verbatim']) && is_string($decoded['verbatim']) ? $decoded['verbatim'] : '',
                'place_query' => isset($decoded['place_query']) && is_string($decoded['place_query']) ? $decoded['place_query'] : '',
            ];
        }

        return [
            'verbatim' => '',
            'place_query' => '',
        ];
    }

    private function geminiErrorResponse(\Illuminate\Http\Client\Response $response): \Illuminate\Http\JsonResponse
    {
        $body = $response->json();
        $message = is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : 'Erreur API Gemini';

        return response()->json([
            'error' => 'Échec de la transcription',
            'details' => $body,
            'message' => $message,
        ], 502);
    }

    private function resolveAudioMimeType(UploadedFile $file): string
    {
        $mime = $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream';
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');

        $allowed = [
            'm4a' => 'audio/mp4',
            'mp4' => 'audio/mp4',
            'aac' => 'audio/aac',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
            'ogg' => 'audio/ogg',
            'opus' => 'audio/opus',
            'caf' => 'audio/x-caf',
            '3gp' => 'audio/3gpp',
        ];

        if ($ext !== '' && isset($allowed[$ext])) {
            return $allowed[$ext];
        }

        if (str_starts_with((string) $mime, 'audio/')) {
            return $mime;
        }

        return 'audio/mp4';
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

    /**
     * @param  bool  $singleLine  true = une ligne (recherche lieu) ; false = conserve les sauts de ligne (message chauffeur).
     */
    private function sanitizeTranscription(string $raw, int $maxLen = self::MAX_TRANSCRIPTION_LENGTH, bool $singleLine = true): string
    {
        $t = trim($raw);
        $t = str_replace(["\r\n", "\r"], "\n", $t);
        $t = preg_replace('/^["«»]+|["«»]+$/u', '', $t) ?? $t;
        $t = trim($t);
        if ($singleLine) {
            $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        } else {
            $t = preg_replace('/\n{3,}/u', "\n\n", $t) ?? $t;
        }

        if (mb_strlen($t) > $maxLen) {
            $t = mb_substr($t, 0, $maxLen);
        }

        return $t;
    }

    private function getLocalContext(): string
    {
        $dbNeighborhoods = \Illuminate\Support\Facades\Cache::remember(
            'voice_all_neighborhoods',
            3600,
            function () {
                $names = \App\Models\Neighborhood::where('is_active', true)
                    ->pluck('name')
                    ->toArray();

                $aliases = \App\Models\Neighborhood::where('is_active', true)
                    ->whereNotNull('aliases')
                    ->pluck('aliases')
                    ->toArray();

                $allWords = [];
                foreach ($names as $name) {
                    $allWords[] = trim($name);
                }
                foreach ($aliases as $aliasStr) {
                    foreach (explode(',', $aliasStr) as $alias) {
                        $trimmed = trim($alias);
                        if ($trimmed !== '') {
                            $allWords[] = $trimmed;
                        }
                    }
                }
                return array_unique($allWords);
            }
        );

        $hardcoded = [
            'Ouando', 'Tokpota', 'Attakè', 'Djassin', 'Agbokou', 'Houinmè',
            'Avassa', 'Kandévié', 'Adjarra proche', 'Catchi', 'Foun-Foun', 'Dowa', 'Kpogbon', 'Marché Ouando', 'CHD',
            'Akpakpa', 'Ganhi', 'Zogbohouè', 'Saint Michel', 'Fidjrossè', 'Cadjèhoun', 'Gbégamey', 'Sainte Rita', 'Agla', 'Ménontin',
            'Sikècodji', 'Kouhounou', 'Godomey', 'Calavi', 'Tankpè', 'Togoudo', 'Zogbadjè', 'Agori', 'Glo-Djigbé'
        ];

        $allNeighborhoods = array_unique(array_merge($hardcoded, $dbNeighborhoods));
        $neighborhoodsStr = implode(', ', $allNeighborhoods);

        return "\n\nCONTEXTE LOCAL IMPORTANT (Bénin: Cotonou, Porto-Novo, Abomey-Calavi) :\n" .
               "Voici la liste des noms de quartiers et lieux connus : " . $neighborhoodsStr . ".\n" .
               "Les utilisateurs peuvent prononcer ces noms avec des accents locaux béninois (Fon, Goun, Yoruba, Mina) ou des variantes phonétiques avec le bruit ambiant.\n" .
               "Règle cruciale : Si ce que tu entends dans l'audio ressemble phonétiquement à l'un de ces noms connus de la liste, tu DOIS impérativement corriger la transcription de ce mot et utiliser l'orthographe EXACTE de ce lieu.";
    }
}
