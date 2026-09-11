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

    public function render()
    {
        return view('livewire.sgra.index');
    }
}
