<?php

namespace App\Console\Commands;

use App\Models\Neighborhood;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GeocodeNeighborhoods extends Command
{
    protected $signature = 'neighborhoods:geocode {--force : Force update even if coordinates exist}';
    protected $description = 'Fetch GPS coordinates for neighborhoods using Mapbox API';

    public function handle()
    {
        $mapboxToken = env('MAPBOX_TOKEN');

        if (!$mapboxToken) {
            $this->error('MAPBOX_TOKEN is not configured in .env');
            return 1;
        }

        $query = Neighborhood::query();

        if (!$this->option('force')) {
            // Only update neighborhoods with default coordinates (Porto-Novo center)
            $query->where(function ($q) {
                $q->whereNull('lat')
                    ->orWhereNull('lng')
                    ->orWhere('lat', 6.4969)
                    ->orWhere('lng', 2.6283);
            });
        }

        $neighborhoods = $query->get();
        $total = $neighborhoods->count();

        if ($total === 0) {
            $this->info('All neighborhoods already have coordinates. Use --force to update all.');
            return 0;
        }

        $this->info("Geocoding {$total} neighborhoods...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $failed = 0;

        foreach ($neighborhoods as $neighborhood) {
            $searchQuery = $neighborhood->name . ', Porto-Novo, Bénin';

            try {
                $response = Http::timeout(5)->get(
                    "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($searchQuery) . ".json",
                    [
                        'access_token' => $mapboxToken,
                        'limit' => 1,
                        'country' => 'bj',
                        'language' => 'fr',
                    ]
                );

                if ($response->ok()) {
                    $features = $response->json()['features'] ?? [];

                    if (!empty($features)) {
                        $coords = $features[0]['center'] ?? null;

                        if ($coords && count($coords) === 2) {
                            $neighborhood->update([
                                'lng' => $coords[0],
                                'lat' => $coords[1],
                            ]);
                            $updated++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $failed++;
                    }
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->warn("Failed for {$neighborhood->name}: " . $e->getMessage());
            }

            // Rate limiting: wait 100ms between requests
            usleep(100000);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Updated: {$updated} neighborhoods");
        if ($failed > 0) {
            $this->warn("⚠️  Failed: {$failed} neighborhoods (will use default coordinates)");
        }

        return 0;
    }
}
