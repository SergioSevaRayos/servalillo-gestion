<?php

namespace App\Livewire\Users;

use App\Livewire\Forms\UserForm;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public UserForm $form;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $role = 'all';

    #[Url(history: true)]
    public string $sort = 'name';

    #[Url(history: true)]
    public string $direction = 'asc';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
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
        $this->authorize('create', User::class);

        $this->form->reset();
        $this->dispatch('open-modal', 'user-form');
    }

    public function edit(User $user): void
    {
        $this->authorize('update', $user);

        $this->form->setUser($user);
        $this->dispatch('open-modal', 'user-form');
    }

    public function save(): void
    {
        $editing = $this->form->editing;

        $editing ? $this->authorize('update', $editing) : $this->authorize('create', User::class);
        $this->authorize('assignRoles', $editing ?? new User());

        $user = $this->form->save();

        $this->dispatch('close-modal', 'user-form');
        $this->dispatch('toast', message: "Usuario {$user->name} guardado correctamente.", variant: 'success');
    }

    public function delete(User $user): void
    {
        $this->authorize('delete', $user);

        $user->delete();

        $this->dispatch('toast', message: 'Usuario eliminado.', variant: 'success');
    }

    public function editing(): bool
    {
        return $this->form->editing !== null;
    }

    public function render()
    {
        $users = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', UserForm::STAFF_ROLES))
            ->with('roles')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$this->search}%")
                ->orWhere('email', 'ilike', "%{$this->search}%")
            ))
            ->when($this->role !== 'all', fn ($q) => $q->whereHas('roles', fn ($q) => $q->where('name', $this->role)))
            ->orderBy($this->sort, $this->direction)
            ->paginate(10);

        return view('livewire.users.index', ['users' => $users]);
    }
}
