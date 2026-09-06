<?php

namespace App\Livewire\Maintenance;

use App\Models\ErrorLog;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Visor del log de Laravel (`storage/logs/*.log`). Solo lectura: lee la cola del archivo
 * (para no cargar en memoria logs enormes) y parte las entradas por su cabecera con fecha.
 */
#[Layout('layouts.app')]
class SystemLog extends Component
{
    /** Cuántos bytes del final del archivo se leen. */
    private const TAIL_BYTES = 512 * 1024;

    /** Máximo de entradas que se muestran tras parsear. */
    private const MAX_ENTRIES = 300;

    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    #[Url(history: true)]
    public string $file = '';

    #[Url(history: true)]
    public string $level = 'all';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ErrorLog::class);

        $files = $this->files();

        if (! in_array($this->file, $files, true)) {
            $this->file = $files[0] ?? '';
        }
    }

    /** @return list<string> nombres de archivo de log, del más reciente al más antiguo */
    #[Computed]
    public function files(): array
    {
        return collect(glob(storage_path('logs/*.log')) ?: [])
            ->sortByDesc(fn (string $path) => filemtime($path))
            ->map(fn (string $path) => basename($path))
            ->values()
            ->all();
    }

    /**
     * @return list<array{datetime: string, env: string, level: string, message: string, body: string}>
     */
    #[Computed]
    public function entries(): array
    {
        $path = $this->safePath();

        if ($path === null || ! is_file($path)) {
            return [];
        }

        $raw = $this->tail($path, self::TAIL_BYTES);

        // Cada entrada empieza por "[2026-09-06 12:34:56] entorno.NIVEL: ..."
        $chunks = preg_split('/(?=^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $entries = [];
        foreach ($chunks as $chunk) {
            if (! preg_match('/^\[(?<dt>[^\]]+)\]\s+(?<env>[\w-]+)\.(?<level>[A-Z]+): (?<msg>.*?)(?:\n|$)(?<body>.*)$/s', $chunk, $m)) {
                continue;
            }

            $entries[] = [
                'datetime' => $m['dt'],
                'env' => $m['env'],
                'level' => strtolower($m['level']),
                'message' => trim($m['msg']),
                'body' => rtrim($m['body']),
            ];
        }

        // Del final del archivo = más recientes primero.
        $entries = array_reverse($entries);

        $entries = array_filter($entries, function (array $e) {
            if ($this->level !== 'all' && $e['level'] !== $this->level) {
                return false;
            }

            if ($this->search !== '' && ! Str::contains($e['message'].$e['body'], $this->search, ignoreCase: true)) {
                return false;
            }

            return true;
        });

        return array_slice(array_values($entries), 0, self::MAX_ENTRIES);
    }

    public function render()
    {
        return view('livewire.maintenance.system-log', [
            'levels' => self::LEVELS,
        ]);
    }

    private function safePath(): ?string
    {
        // Solo un nombre de archivo .log dentro de storage/logs — sin path traversal.
        if ($this->file === '' || ! preg_match('/^[\w.-]+\.log$/', $this->file)) {
            return null;
        }

        $path = storage_path('logs/'.$this->file);

        return str_starts_with(realpath($path) ?: '', realpath(storage_path('logs')) ?: 'x')
            ? $path
            : null;
    }

    private function tail(string $path, int $bytes): string
    {
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        if ($size > $bytes) {
            fseek($handle, -$bytes, SEEK_END);
            fgets($handle); // descarta la primera línea (probablemente parcial)
        }

        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }
}
