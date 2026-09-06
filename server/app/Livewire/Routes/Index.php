<?php

namespace App\Livewire\Routes;

use App\Enums\RouteStatus;
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
    public string $status = 'all';

    #[Url(history: true)]
    public string $sort = 'route_date';

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

    public function updatingStatus(): void
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
        $this->form->route_date = now()->toDateString();
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

    public function delete(Route $route): void
    {
        $this->authorize('delete', $route);

        $route->delete();

        $this->dispatch('toast', message: 'Ruta eliminada.', variant: 'success');
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
    public function statuses(): array
    {
        return RouteStatus::cases();
    }

    public function render()
    {
        $routes = Route::query()
            ->with(['truck', 'driver.user'])
            ->withCount('stops')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$this->search}%")
                ->orWhereHas('truck', fn ($q) => $q->where('code', 'ilike', "%{$this->search}%"))
                ->orWhereHas('driver.user', fn ($q) => $q->where('name', 'ilike', "%{$this->search}%"))
            ))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->orderBy($this->sort, $this->direction)
            ->paginate(10);

        return view('livewire.routes.index', ['routes' => $routes]);
    }
}
