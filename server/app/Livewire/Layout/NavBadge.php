<?php

namespace App\Livewire\Layout;

use App\Models\AlertEvent;
use App\Models\Invoice;
use Livewire\Component;

/**
 * Insignia viva del menú lateral (facturas pendientes / avisos activos). Antes el número se
 * calculaba UNA vez al cargar la página y se quedaba congelado hasta navegar: ahora cada insignia
 * es su propio componente con `wire:poll` y se actualiza sola, sin recargar nada.
 */
class NavBadge extends Component
{
    /** invoices | alerts */
    public string $kind = 'alerts';

    public function count(): int
    {
        $user = auth()->user();

        return match ($this->kind) {
            'invoices' => $user?->can('invoices.manage') ? Invoice::query()->pending()->count() : 0,
            'alerts' => $user?->can('alerts.manage') ? AlertEvent::query()->open()->count() : 0,
            default => 0,
        };
    }

    public function render()
    {
        return view('livewire.layout.nav-badge', [
            'count' => $this->count(),
            'color' => $this->kind === 'invoices' ? 'bg-amber-500' : 'bg-rose-500',
        ]);
    }
}
