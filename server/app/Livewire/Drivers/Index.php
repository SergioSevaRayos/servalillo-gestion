<?php

namespace App\Livewire\Drivers;

use App\Livewire\Forms\DriverForm;
use App\Models\Driver;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public DriverForm $form;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(history: true)]
    public string $sort = 'employee_code';

    #[Url(history: true)]
    public string $direction = 'asc';

    public function mount(): void
    {
        $this->authorize('viewAny', Driver::class);
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
        $this->authorize('create', Driver::class);

        $this->form->reset();
        $this->dispatch('open-modal', 'driver-form');
    }

    public function edit(Driver $driver): void
    {
        $this->authorize('update', $driver);

        $this->form->setDriver($driver);
        $this->dispatch('open-modal', 'driver-form');
    }

    public function save(): void
    {
        $this->editing()
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', Driver::class);

        $this->form->save();

        $this->dispatch('close-modal', 'driver-form');
        $this->dispatch('toast', message: 'Chofer guardado correctamente.', variant: 'success');
    }

    public function delete(Driver $driver): void
    {
        $this->authorize('delete', $driver);

        $driver->delete();

        $this->dispatch('toast', message: 'Chofer eliminado.', variant: 'success');
    }

    public function editing(): bool
    {
        return $this->form->editing !== null;
    }

    public function render()
    {
        $drivers = Driver::query()
            ->with('user')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('employee_code', 'ilike', "%{$this->search}%")
                ->orWhereHas('user', fn ($q) => $q
                    ->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('email', 'ilike', "%{$this->search}%")
                )
            ))
            ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->when(
                in_array($this->sort, ['employee_code', 'license_expiry', 'is_active']),
                fn ($q) => $q->orderBy($this->sort, $this->direction)
            )
            ->when($this->sort === 'name', fn ($q) => $q
                ->join('users', 'users.id', '=', 'drivers.user_id')
                ->orderBy('users.name', $this->direction)
                ->select('drivers.*'))
            ->paginate(10);

        return view('livewire.drivers.index', ['drivers' => $drivers]);
    }
}
