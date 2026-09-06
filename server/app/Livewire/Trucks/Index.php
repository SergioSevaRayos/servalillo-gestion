<?php

namespace App\Livewire\Trucks;

use App\Livewire\Forms\TruckForm;
use App\Models\Truck;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public TruckForm $form;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(history: true)]
    public string $sort = 'code';

    #[Url(history: true)]
    public string $direction = 'asc';

    public function mount(): void
    {
        $this->authorize('viewAny', Truck::class);
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
        $this->authorize('create', Truck::class);

        $this->form->reset();
        $this->dispatch('open-modal', 'truck-form');
    }

    public function edit(Truck $truck): void
    {
        $this->authorize('update', $truck);

        $this->form->setTruck($truck);
        $this->dispatch('open-modal', 'truck-form');
    }

    public function save(): void
    {
        $this->editing()
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', Truck::class);

        $this->form->save();

        $this->dispatch('close-modal', 'truck-form');
        $this->dispatch('toast', message: 'Camión guardado correctamente.', variant: 'success');
    }

    public function delete(Truck $truck): void
    {
        $this->authorize('delete', $truck);

        $truck->delete();

        $this->dispatch('toast', message: 'Camión eliminado.', variant: 'success');
    }

    public function editing(): bool
    {
        return $this->form->editing !== null;
    }

    public function render()
    {
        $trucks = Truck::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('plate', 'ilike', "%{$this->search}%")
                ->orWhere('code', 'ilike', "%{$this->search}%")
                ->orWhere('model', 'ilike', "%{$this->search}%")
            ))
            ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->orderBy($this->sort, $this->direction)
            ->paginate(10);

        return view('livewire.trucks.index', ['trucks' => $trucks]);
    }
}
