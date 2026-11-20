<?php

namespace App\Livewire\Manual;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manual de uso: dos manuales en Markdown (`resources/manuales/*.md`) renderizados en la app.
 * Administrador y mantenimiento ven los dos (con selector); el chofer solo el suyo.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    /** Manuales existentes: clave de URL => título. */
    public const MANUALS = [
        'administracion' => 'Administración',
        'chofer' => 'Chofer',
    ];

    public string $type = 'chofer';

    public function mount(?string $type = null): void
    {
        $user = auth()->user();
        $allowed = $user->isManager() ? array_keys(self::MANUALS) : ['chofer'];

        $type ??= $user->isManager() ? 'administracion' : 'chofer';

        abort_unless(in_array($type, $allowed, true), 403);

        $this->type = $type;
    }

    /** Manuales que puede abrir el usuario actual (para el selector). */
    #[Computed]
    public function available(): array
    {
        return auth()->user()->isManager() ? self::MANUALS : ['chofer' => self::MANUALS['chofer']];
    }

    /** @return array{html: string, toc: array<int, array{id: string, title: string}>} */
    #[Computed]
    public function manual(): array
    {
        $path = resource_path("manuales/{$this->type}.md");
        $markdown = is_file($path) ? file_get_contents($path) : '';

        $toc = [];

        // Contenido del repo (de confianza), pero sin HTML crudo ni enlaces peligrosos igualmente.
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        // Cada captura se abre a tamaño completo en otra pestaña al pulsarla.
        $html = preg_replace_callback('#<img src="([^"]+)"([^>]*)>#', function ($m) {
            return '<a href="'.$m[1].'" target="_blank" rel="noopener"><img src="'.$m[1].'"'.rtrim($m[2], ' /').' loading="lazy"></a>';
        }, $html);

        // Ancla por sección (##) para el índice.
        $html = preg_replace_callback('#<h2>(.*?)</h2>#s', function ($m) use (&$toc) {
            $title = trim(strip_tags($m[1]));
            $id = Str::slug($title);
            $toc[] = ['id' => $id, 'title' => $title];

            return '<h2 id="'.e($id).'">'.$m[1].'</h2>';
        }, $html);

        return ['html' => $html, 'toc' => $toc];
    }

    public function render()
    {
        return view('livewire.manual.show', [
            'title' => __('Manual de uso').' · '.self::MANUALS[$this->type],
        ])->layout('layouts.app', ['header' => __('Manual de uso')]);
    }
}
