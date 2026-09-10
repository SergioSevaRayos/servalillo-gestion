<?php

namespace App\Livewire\Routes;

use App\Livewire\Forms\TruckAssignmentForm;
use App\Models\Driver;
use App\Models\Truck;
use App\Models\TruckAssignment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Assignments extends Component
{
    use WithPagination;

    public TruckAssignmentForm $form;

    public function mount(): void
    {
        $this->authorize('viewAny', TruckAssignment::class);
    }

    public function create(): void
    {
        $this->authorize('create', TruckAssignment::class);

        $this->form->reset();
        $this->form->valid_from = now()->toDateString();
        $this->dispatch('open-modal', 'assignment-form');
    }

    public function edit(TruckAssignment $assignment): void
    {
        $this->authorize('update', $assignment);

        $this->form->setAssignment($assignment);
        $this->dispatch('open-modal', 'assignment-form');
    }

    public function save(): void
    {
        $this->editing()
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', TruckAssignment::class);

        $this->form->save();

        $this->dispatch('close-modal', 'assignment-form');
        $this->dispatch('toast', message: 'Asignación guardada correctamente.', variant: 'success');
    }

    /** Termina hoy la asignación sin abrir el formulario completo. */
    public function finalize(TruckAssignment $assignment): void
    {
        $this->authorize('update', $assignment);

        $assignment->update(['valid_until' => now()->toDateString()]);

        $this->dispatch('toast', message: 'Asignación finalizada hoy.', variant: 'success');
    }

    public function delete(TruckAssignment $assignment): void
    {
        $this->authorize('delete', $assignment);

        $assignment->delete();

        $this->dispatch('toast', message: 'Asignación eliminada.', variant: 'success');
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

    public function render()
    {
        $assignments = TruckAssignment::query()
            ->with(['truck', 'driver.user'])
            ->orderByDesc('valid_from')
            ->paginate(10);

        return view('livewire.routes.assignments', ['assignments' => $assignments]);
    }
}
