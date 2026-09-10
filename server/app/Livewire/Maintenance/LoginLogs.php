<?php

namespace App\Livewire\Maintenance;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class LoginLogs extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    /** @var array<int, string> ids (como string, formato nativo de wire:model en checkboxes) seleccionados */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('viewAny', LoginLog::class);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'from', 'to'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'from', 'to');
        $this->resetPage();
        $this->selected = [];
    }

    /** Marca/desmarca todas las filas de la página actual (no las de otras páginas). */
    public function toggleSelectAll(): void
    {
        $pageIds = $this->baseQuery()
            ->latest('logged_in_at')
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->selected = count(array_intersect($pageIds, $this->selected)) === count($pageIds)
            ? array_values(array_diff($this->selected, $pageIds))
            : array_values(array_unique([...$this->selected, ...$pageIds]));
    }

    public function deleteSelected(): void
    {
        $this->authorize('viewAny', LoginLog::class);

        $count = LoginLog::whereIn('id', $this->selected)->delete();
        $this->selected = [];

        $this->dispatch('toast', message: "Eliminados {$count} accesos.", variant: 'success');
    }

    public function purgeOld(): void
    {
        $this->authorize('viewAny', LoginLog::class);

        $days = (int) config('servalillo.login_log_retention_days');
        $deleted = LoginLog::where('logged_in_at', '<', now()->subDays($days))->delete();

        $this->resetPage();
        $this->dispatch('toast', message: "Eliminados {$deleted} accesos de más de {$days} días.", variant: 'success');
    }

    /** Usuarios con actividad reciente. */
    #[Computed]
    public function onlineUsers()
    {
        $cutoff = now()->subMinutes((int) config('servalillo.online_window_minutes'));

        return User::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $cutoff)
            ->orderByDesc('last_seen_at')
            ->get(['id', 'name', 'last_seen_at']);
    }

    private function baseQuery(): Builder
    {
        return LoginLog::query()
            ->when($this->search, function ($q) {
                $term = "%{$this->search}%";
                $q->whereHas('user', fn ($q) => $q
                    ->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term));
            })
            ->when($this->from, fn ($q) => $q->where('logged_in_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to, fn ($q) => $q->where('logged_in_at', '<=', Carbon::parse($this->to)->endOfDay()));
    }

    public function render()
    {
        $logins = $this->baseQuery()
            ->with('user')
            ->latest('logged_in_at')
            ->paginate(20);

        return view('livewire.maintenance.login-logs', [
            'logins' => $logins,
            'oldCount' => LoginLog::where('logged_in_at', '<', now()->subDays((int) config('servalillo.login_log_retention_days')))->count(),
        ]);
    }
}
