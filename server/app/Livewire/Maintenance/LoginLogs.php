<?php

namespace App\Livewire\Maintenance;

use App\Models\LoginLog;
use Illuminate\Support\Carbon;
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

    public function mount(): void
    {
        $this->authorize('viewAny', LoginLog::class);
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

    public function render()
    {
        $logins = LoginLog::query()
            ->with('user')
            ->when($this->search, function ($q) {
                $term = "%{$this->search}%";
                $q->whereHas('user', fn ($q) => $q
                    ->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term));
            })
            ->when($this->from, fn ($q) => $q->where('logged_in_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to, fn ($q) => $q->where('logged_in_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->latest('logged_in_at')
            ->paginate(20);

        return view('livewire.maintenance.login-logs', ['logins' => $logins]);
    }
}
