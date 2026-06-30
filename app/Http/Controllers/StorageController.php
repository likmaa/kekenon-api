<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    /**
     * Sert un fichier depuis le disque public de stockage.
     * Route : GET /api/storage/{path}
     * Exemple : /api/storage/profiles/abc123.jpg
     */
    public function show(string $path)
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            abort(404, 'Fichier introuvable.');
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($path)) {
            abort(404, 'Fichier introuvable.');
        }

        $fullPath = realpath($disk->path($path));
        $basePath = realpath($disk->path(''));
        if ($fullPath === false || $basePath === false) {
            abort(404, 'Fichier introuvable.');
        }

        $basePrefix = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($fullPath !== $basePath && !str_starts_with($fullPath, $basePrefix)) {
            abort(404, 'Fichier introuvable.');
        }

        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
