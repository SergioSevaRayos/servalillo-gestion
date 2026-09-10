<?php

namespace App\Livewire\Routes;

use App\Enums\ServiceKind;
use App\Livewire\Forms\RouteForm;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Truck;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public RouteForm $form;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $sort = 'valid_from';

    #[Url(history: true)]
    public string $direction = 'desc';

    public function mount(): void
    {
        $this->authorize('viewAny', Route::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sort === $field) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->direction = 'asc';
        }
    }

    public function create(): void
    {
        $this->authorize('create', Route::class);

        $this->form->reset();
        $this->form->valid_from = now()->toDateString();
        $this->dispatch('open-modal', 'route-form');
    }

    public function edit(Route $route): void
    {
        $this->authorize('update', $route);

        $this->form->setRoute($route);
        $this->dispatch('open-modal', 'route-form');
    }

    public function save(): void
    {
        $this->editing()
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', Route::class);

        $this->form->save();

        $this->dispatch('close-modal', 'route-form');
        $this->dispatch('toast', message: 'Ruta guardada correctamente.', variant: 'success');
    }

    /** Termina hoy la ruta sin abrir el formulario completo. */
    public function finalize(Route $route): void
    {
        $this->authorize('update', $route);

        $route->update(['valid_until' => now()->toDateString()]);

        $this->dispatch('toast', message: 'Ruta finalizada hoy.', variant: 'success');
    }

    public function delete(Route $route): void
    {
        $this->authorize('delete', $route);

        // La ruta permanente es SoftDeletes; su historial de días (RouteDay) no se toca —
        // sigue viéndose desde "Historial". Solo deja de generar días nuevos a partir de hoy.
        $route->delete();

        $this->dispatch('toast', message: 'Ruta eliminada. Su historial de días no se toca.', variant: 'success');
    }

    public function editing(): bool
    {
        return $this->form->editing !== null;
    }

    #[Computed]
    public function trucks()
    {
        return Truck::where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function drivers()
    {
        return Driver::with('user')->where('is_active', true)->get()->sortBy(fn ($d) => $d->user->name);
    }

    #[Computed]
    public function serviceKinds(): array
    {
        return ServiceKind::cases();
    }

    public function render()
    {
        $routes = Route::query()
            ->with(['truck', 'driver.user'])
            ->withCount('routeDays')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$this->search}%")
                ->orWhereHas('truck', fn ($q) => $q->where('code', 'ilike', "%{$this->search}%"))
                ->orWhereHas('driver.user', fn ($q) => $q->where('name', 'ilike', "%{$this->search}%"))
            ))
            ->orderBy($this->sort, $this->direction)
            ->paginate(10);

        return view('livewire.routes.index', ['routes' => $routes]);
    }
}
