<?php

namespace App\Livewire\Maintenance;

use App\Models\ErrorLog;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Errors extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ErrorLog::class);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'from', 'to');
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('open-modal', 'error-detail');
    }

    #[Computed]
    public function selected(): ?ErrorLog
    {
        return $this->selectedId ? ErrorLog::with('user')->find($this->selectedId) : null;
    }

    public function deleteLog(int $id): void
    {
        $this->authorize('viewAny', ErrorLog::class);

        ErrorLog::whereKey($id)->delete();

        $this->selectedId = null;
        $this->dispatch('close-modal', 'error-detail');
        $this->dispatch('toast', message: 'Registro de error eliminado.', variant: 'success');
    }

    public function purgeOld(): void
    {
        $this->authorize('viewAny', ErrorLog::class);

        $deleted = ErrorLog::where('occurred_at', '<', now()->subDays(30))->delete();

        $this->resetPage();
        $this->dispatch('toast', message: "Eliminados {$deleted} errores de más de 30 días.", variant: 'success');
    }

    public function render()
    {
        $errors = ErrorLog::query()
            ->with('user')
            ->when($this->search, function ($q) {
                $term = "%{$this->search}%";
                $q->where(fn ($q) => $q
                    ->where('message', 'ilike', $term)
                    ->orWhere('exception_class', 'ilike', $term)
                    ->orWhere('url', 'ilike', $term));
            })
            ->when($this->from, fn ($q) => $q->where('occurred_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to, fn ($q) => $q->where('occurred_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->latest('occurred_at')
            ->paginate(20);

        return view('livewire.maintenance.errors', [
            'errors' => $errors,
            'oldCount' => ErrorLog::where('occurred_at', '<', now()->subDays(30))->count(),
        ]);
    }
}
