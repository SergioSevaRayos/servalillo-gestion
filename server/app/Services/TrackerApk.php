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
     * @return array{version: ?string, built_at: ?Carbon, size: int, sha256: ?string}|null
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
            'built_at' => isset($meta['built_at']) ? Carbon::parse($meta['built_at']) : Carbon::createFromTimestamp($disk->lastModified(self::FILE)),
            'size' => $disk->size(self::FILE),
            'sha256' => is_string($meta['sha256'] ?? null) ? $meta['sha256'] : null,
        ];
    }

    /** Nombre del fichero descargado, con la versión si se conoce. */
    public function downloadName(): string
    {
        $version = $this->info()['version'] ?? null;

        return 'servalillo-tracker'.($version ? '-'.preg_replace('/[^0-9A-Za-z.\-]/', '', $version) : '').'.apk';
    }
}
