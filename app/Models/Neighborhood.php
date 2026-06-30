<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Neighborhood extends Model
{
    protected $fillable = [
        'name',
        'arrondissement',
        'city',
        'country',
        'lat',
        'lng',
        'aliases',
        'is_active',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Search neighborhoods by name, arrondissement, or alias with fuzzy phonetic matching.
     */
    public static function search(string $query, int $limit = 10)
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        // Split query into terms to support multi-word search (e.g. "st michel")
        $terms = array_filter(explode(' ', preg_replace('/\s+/', ' ', $query)));

        $dbQuery = self::where('is_active', true);

        $dbQuery->where(function ($q) use ($query, $terms) {
            // 1. Direct LIKE matches
            $q->where('name', 'LIKE', "%{$query}%")
              ->orWhere('aliases', 'LIKE', "%{$query}%")
              ->orWhere('arrondissement', 'LIKE', "%{$query}%");

            // 2. Individual word matching (fuzzy lookup for multi-term queries)
            if (count($terms) > 1) {
                $q->orWhere(function ($sub) use ($terms) {
                    foreach ($terms as $term) {
                        $sub->where(function ($inner) use ($term) {
                            $inner->where('name', 'LIKE', "%{$term}%")
                                  ->orWhere('aliases', 'LIKE', "%{$term}%");
                        });
                    }
                });
            }

            // 3. SOUNDEX phonetic matching on MySQL/MariaDB (with try/catch for SQLite compatibility)
            try {
                $driver = \Illuminate\Support\Facades\DB::getDriverName();
                if ($driver === 'mysql') {
                    $q->orWhereRaw("SOUNDEX(name) = SOUNDEX(?)", [$query])
                      ->orWhereRaw("SOUNDEX(aliases) = SOUNDEX(?)", [$query]);

                    foreach ($terms as $term) {
                        if (strlen($term) > 3) {
                            $q->orWhereRaw("SOUNDEX(name) LIKE CONCAT('%', SOUNDEX(?), '%')", [$term])
                              ->orWhereRaw("SOUNDEX(aliases) LIKE CONCAT('%', SOUNDEX(?), '%')", [$term]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // S'exécute silencieusement si la fonction SOUNDEX n'est pas supportée localement
            }
        });

        // Priority sorting : exact match > starts with > substring
        return $dbQuery->orderByRaw("
            CASE
                WHEN name = ? THEN 0
                WHEN name LIKE ? THEN 1
                WHEN name LIKE ? THEN 2
                ELSE 3
            END",
            [$query, "{$query}%", "%{$query}%"]
        )
        ->limit($limit)
        ->get();
    }
}
