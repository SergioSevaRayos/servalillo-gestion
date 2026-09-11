<?php

namespace App\Livewire\Sgra;

use App\Services\SgraClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Nivel actual de los depósitos del proyecto SGRA (externo), leído en vivo vía
 * App\Services\SgraClient. Mismo patrón de autorización que Dashboard\Index:
 * middleware de ruta (`permission:sgra.view`) + abort_unless en mount() (los tests
 * vía Livewire::test se saltan el middleware de ruta).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('sgra.view'), 403);
    }

    #[Computed]
    public function tanks(): array
    {
        return app(SgraClient::class)->tanks();
    }

    public function publicUrl(): string
    {
        return config('servalillo.sgra.public_url');
    }

    /**
     * Llamado por wire:poll: no solo re-renderiza (el visual 3D vive en un bloque
     * wire:ignore, así que el morph de Livewire no lo toca), sino que además emite el
     * dato fresco por evento — mismo patrón que setRange() en Dashboard\Index con
     * statsCharts, pero disparado por el poll en vez de por un clic.
     */
    public function pollTanks(): void
    {
        $this->dispatch('tanks-updated', tanks: app(SgraClient::class)->tanks());
    }

    public function render()
    {
        return view('livewire.sgra.index');
    }
}
