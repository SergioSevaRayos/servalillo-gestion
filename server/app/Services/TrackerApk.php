<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * La APK tracker publicada para descargarla desde /dispositivos (docs/24).
 *
 * No va en git (pesa ~60 MB): la compila y sube `server/deploy/publish-apk.sh` a
 * `storage/app/private/apk/` (disco `local`), con un `tracker.json` al lado con la versión.
 */
class TrackerApk
{
    public const DIR = 'apk';

    public const FILE = self::DIR.'/servalillo-tracker.apk';

    public const META = self::DIR.'/tracker.json';

    public function exists(): bool
    {
        return Storage::disk('local')->exists(self::FILE);
    }

    public function path(): string
    {
        return Storage::disk('local')->path(self::FILE);
    }

    /**
     * @return array{version: ?string, version_code: ?int, built_at: ?Carbon, size: int, sha256: ?string}|null
     */
    public function info(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $disk = Storage::disk('local');
        $meta = json_decode((string) ($disk->exists(self::META) ? $disk->get(self::META) : ''), true) ?: [];

        return [
            'version' => is_string($meta['version'] ?? null) ? $meta['version'] : null,
            // versionCode de Android (el "+N" de mobile/pubspec.yaml): la APK lo compara con el suyo
            // para actualizarse sola (docs/24).
            'version_code' => is_numeric($meta['version_code'] ?? null) ? (int) $meta['version_code'] : null,
            'built_at' => isset($meta['built_at']) ? Carbon::parse($meta['built_at']) : Carbon::createFromTimestamp($disk->lastModified(self::FILE)),
            'size' => $disk->size(self::FILE),
            'sha256' => is_string($meta['sha256'] ?? null) ? $meta['sha256'] : null,
        ];
    }

    /**
     * Lo que la APK necesita para decidir si se actualiza sola (GET /api/device → app_update).
     * Null si no hay APK publicada o no se sabe su versionCode/huella.
     *
     * @return array{version: ?string, version_code: int, sha256: string, size: int}|null
     */
    public function updateInfo(): ?array
    {
        $info = $this->info();

        if (! $info || $info['version_code'] === null || $info['sha256'] === null) {
            return null;
        }

        return [
            'version' => $info['version'],
            'version_code' => $info['version_code'],
            'sha256' => $info['sha256'],
            'size' => $info['size'],
        ];
    }

    /** Nombre del fichero descargado, con la versión si se conoce. */
    public function downloadName(): string
    {
        $version = $this->info()['version'] ?? null;

        return 'servalillo-tracker'.($version ? '-'.preg_replace('/[^0-9A-Za-z.\-]/', '', $version) : '').'.apk';
    }
}
