<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\GeminiGeocodingAssistant;

class GeocodingController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2'],
            'language' => ['sometimes', 'string', 'size:2'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'lat' => ['sometimes', 'numeric'],
            'lon' => ['sometimes', 'numeric'],
        ]);

        $query = trim($validated['query']);
        $language = $validated['language'] ?? 'fr';
        $limit = $validated['limit'] ?? 8;
        $lat = $request->lat ? round((float) $request->lat, 2) : null;
        $lon = $request->lon ? round((float) $request->lon, 2) : null;

        $cacheKey = "geocode:search:" . md5($language . '|' . $query . '|' . $limit . '|' . $lat . '|' . $lon);
        $started = microtime(true);
        $ip = $request->ip();
        $uid = optional($request->user())->id;

        // Log the request parameters
        Log::info("Geocoding search request", ['query' => $request->query('query'), 'lat' => $request->lat, 'lon' => $request->lon]);

        $results = Cache::remember($cacheKey, 3600, function () use ($query, $language, $limit, $request) {
            $mapboxToken = env('MAPBOX_TOKEN');

            // On demande plus de résultats en interne (20 au lieu de 8) pour avoir plus de choix lors du tri par distance
            $internalLimit = 20;

            $combined = [];

            // 0. Recherche locale dans les quartiers de la base de données (prioritaire)
            $localNeighborhoods = \App\Models\Neighborhood::search($query, 10);
            foreach ($localNeighborhoods as $neighborhood) {
                // Default centers for Beninese cities
                $defaultLat = $neighborhood->city === 'Cotonou' ? '6.3667' : ($neighborhood->city === 'Abomey-Calavi' ? '6.4481' : '6.4969');
                $defaultLng = $neighborhood->city === 'Cotonou' ? '2.4333' : ($neighborhood->city === 'Abomey-Calavi' ? '2.3533' : '2.6283');

                $combined[] = [
                    'place_id' => 'local_' . $neighborhood->id,
                    'display_name' => $neighborhood->name . ' (' . $neighborhood->arrondissement . ', ' . $neighborhood->city . ')',
                    'lat' => (string) ($neighborhood->lat ?? $defaultLat),
                    'lon' => (string) ($neighborhood->lng ?? $defaultLng),
                    'source' => 'local',
                    'priority' => 0, // Highest priority
                ];
            }

            // 1. Appel Mapbox
            $mapboxUrl = "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($query) . ".json";
            $mapboxParams = [
                'access_token' => $mapboxToken,
                'language' => $language,
                'limit' => $internalLimit,
                'country' => 'bj',
                'bbox' => '2.10,6.30,2.70,6.85',
                /** Partiels plus naturels (saisie dans l’app). */
                'autocomplete' => 'true',
            ];
            if ($request->has('lat') && $request->has('lon')) {
                $mapboxParams['proximity'] = $request->lon . ',' . $request->lat;
            }

            // 2. Appel Nominatim — même enveloppe que Mapbox (minLon, maxLat, maxLon, minLat pour viewbox)
            $nominatimUrl = 'https://nominatim.openstreetmap.org/search';
            $nominatimParams = [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => $internalLimit,
                'accept-language' => $language,
                'countrycodes' => 'bj',
                'viewbox' => '2.10,6.85,2.70,6.30',
                'bounded' => '1',
            ];

            $responses = Http::pool(fn($pool) => [
                $pool->as('mapbox')->timeout(4)->get($mapboxUrl, $mapboxParams),
                $pool->as('nominatim')->timeout(4)->withHeaders(['User-Agent' => 'PortoBackend/1.0'])->get($nominatimUrl, $nominatimParams),
            ]);

            // Traitement Mapbox — place_name = libellé complet (meilleure lisibilité dans la liste app)
            if ($responses['mapbox'] instanceof \Illuminate\Http\Client\Response && $responses['mapbox']->ok()) {
                foreach ($responses['mapbox']->json()['features'] ?? [] as $f) {
                    $name = $f['text'] ?? '';
                    $context = $f['context'] ?? [];
                    $neighborhood = null;
                    foreach ($context as $c) {
                        if (strpos($c['id'] ?? '', 'neighborhood') !== false || strpos($c['id'] ?? '', 'locality') !== false) {
                            $neighborhood = $c['text'] ?? null;
                            break;
                        }
                    }
                    $shortLabel = $neighborhood ? "$name ($neighborhood)" : $name;
                    $fullLabel = isset($f['place_name']) && is_string($f['place_name']) && $f['place_name'] !== ''
                        ? $f['place_name']
                        : $shortLabel;
                    $combined[] = [
                        'place_id' => 'mb_' . ($f['id'] ?? ''),
                        'display_name' => $fullLabel,
                        'lat' => (string) ($f['center'][1] ?? ''),
                        'lon' => (string) ($f['center'][0] ?? ''),
                        'source' => 'mapbox'
                    ];
                }
            }

            // Traitement Nominatim
            if ($responses['nominatim'] instanceof \Illuminate\Http\Client\Response && $responses['nominatim']->ok()) {
                foreach ($this->mapNominatimFeaturesToItems($responses['nominatim']->json() ?? [], 'nominatim') as $row) {
                    $combined[] = $row;
                }
            }

            // Aucun résultat : Gemini propose des variantes de requête, puis second passage Nominatim uniquement.
            if ($combined === []) {
                $latF = $request->has('lat') ? (float) $request->lat : null;
                $lonF = $request->has('lon') ? (float) $request->lon : null;
                $alternatives = app(GeminiGeocodingAssistant::class)->suggestNominatimQueries($query, $latF, $lonF, $language);
                foreach ($alternatives as $altQ) {
                    if (mb_strtolower(trim($altQ)) === mb_strtolower(trim($query))) {
                        continue;
                    }
                    $retry = Http::timeout(5)->withHeaders(['User-Agent' => 'PortoBackend/1.0'])->get($nominatimUrl, array_merge($nominatimParams, [
                        'q' => $altQ,
                        'limit' => 8,
                    ]));
                    if ($retry->ok()) {
                        foreach ($this->mapNominatimFeaturesToItems($retry->json() ?? [], 'nominatim_gemini') as $row) {
                            $combined[] = $row;
                        }
                    }
                }
            }

            $unique = [];
            $seen = [];
            $userLat = $request->lat;
            $userLon = $request->lon;

            foreach ($combined as $item) {
                $slug = Str::slug($item['display_name']);

                // On privilégie les POIs (Points d'Intérêt) par rapport aux adresses génériques
                $item['distance'] = null;
                if ($userLat && $userLon && $item['lat'] && $item['lon']) {
                    // Haversine simple
                    $item['distance'] = sqrt(pow((float) $item['lat'] - (float) $userLat, 2) + pow((float) $item['lon'] - (float) $userLon, 2));
                }

                if (!isset($seen[$slug])) {
                    $unique[] = $item;
                    $seen[$slug] = true;
                }
            }

            if ($userLat && $userLon) {
                usort($unique, function ($a, $b) {
                    // First: prioritize local results (priority = 0)
                    $aPriority = $a['priority'] ?? 1;
                    $bPriority = $b['priority'] ?? 1;
                    if ($aPriority !== $bPriority) {
                        return $aPriority - $bPriority;
                    }
                    // Then: sort by distance
                    if ($a['distance'] === $b['distance'])
                        return 0;
                    if ($a['distance'] === null)
                        return 1;
                    if ($b['distance'] === null)
                        return -1;
                    return ($a['distance'] < $b['distance']) ? -1 : 1;
                });
            } else {
                // No user coordinates: just sort by priority
                usort($unique, function ($a, $b) {
                    return ($a['priority'] ?? 1) - ($b['priority'] ?? 1);
                });
            }

            return ['items' => array_slice($unique, 0, 15), 'status' => 200];
        });

        $duration = (int) round((microtime(true) - $started) * 1000);
        try {
            DB::table('geocoding_logs')->insert([
                'user_id' => $uid,
                'ip' => $ip,
                'type' => 'search',
                'query' => $query,
                'lat' => $request->lat,
                'lon' => $request->lon,
                'provider' => 'hybrid',
                'status' => $results['status'] ?? null,
                'duration_ms' => $duration,
                'result_count' => count($results['items'] ?? []),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'results' => $results['items'] ?? [],
        ]);
    }

    public function reverse(Request $request)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric'],
            'lon' => ['required', 'numeric'],
            'language' => ['sometimes', 'string', 'size:2'],
        ]);
        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $language = $validated['language'] ?? 'fr';

        $cacheKey = "geocode:reverse:" . md5($language . '|' . $lat . '|' . $lon);
        $started = microtime(true);
        $ip = $request->ip();
        $uid = optional($request->user())->id;

        $data = Cache::remember($cacheKey, 3600, function () use ($lat, $lon, $language) {
            $token = env('MAPBOX_TOKEN');
            $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$lon},{$lat}.json";

            $resp = Http::timeout(6)->get($url, [
                'access_token' => $token,
                'language' => $language,
                'limit' => 1,
                'types' => 'address,poi,neighborhood,locality'
            ]);

            if (!$resp->ok())
                return ['address' => null, 'label' => null, 'status' => $resp->status()];

            $json = $resp->json();
            $feature = $json['features'][0] ?? null;

            if (!$feature)
                return ['address' => null, 'label' => null, 'status' => $resp->status()];

            $addr = $feature['place_name'] ?? null;
            $name = $feature['text'] ?? null;

            // Extraction du quartier
            $context = $feature['context'] ?? [];
            $neighborhood = null;
            foreach ($context as $c) {
                if (strpos($c['id'] ?? '', 'neighborhood') !== false || strpos($c['id'] ?? '', 'locality') !== false) {
                    $neighborhood = $c['text'] ?? null;
                    break;
                }
            }

            $label = $name;
            if ($neighborhood && strpos($name, $neighborhood) === false) {
                $label = $name . ', ' . $neighborhood;
            }

            return [
                'address' => $addr,
                'label' => $label,
                'status' => $resp->status(),
            ];
        });

        $duration = (int) round((microtime(true) - $started) * 1000);
        try {
            DB::table('geocoding_logs')->insert([
                'user_id' => $uid,
                'ip' => $request->ip(),
                'type' => 'reverse',
                'query' => null,
                'lat' => $lat,
                'lon' => $lon,
                'provider' => 'mapbox',
                'status' => $data['status'] ?? null,
                'duration_ms' => $duration,
                'result_count' => isset($data['address']) && $data['address'] ? 1 : 0,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'address' => $data['address'] ?? null,
            'label' => $data['label'] ?? null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return list<array<string, mixed>>
     */
    private function mapNominatimFeaturesToItems(array $features, string $source): array
    {
        $out = [];
        foreach ($features as $f) {
            if (! is_array($f)) {
                continue;
            }
            $addr = $f['address'] ?? [];
            $neighborhood = $addr['neighbourhood'] ?? ($addr['suburb'] ?? ($addr['quarter'] ?? null));
            $name = $f['name'] ?? ($f['display_name'] ?? '');

            $shortName = current(explode(',', (string) $name));
            $fullNominatim = isset($f['display_name']) && is_string($f['display_name']) && $f['display_name'] !== ''
                ? $f['display_name']
                : ($neighborhood ? "$shortName ($neighborhood)" : $shortName);

            $prefix = $source === 'nominatim_gemini' ? 'nomai_' : 'nom_';
            $out[] = [
                'place_id' => $prefix.($f['place_id'] ?? ''),
                'display_name' => $fullNominatim,
                'lat' => (string) ($f['lat'] ?? ''),
                'lon' => (string) ($f['lon'] ?? ''),
                'source' => $source,
            ];
        }

        return $out;
    }
}
