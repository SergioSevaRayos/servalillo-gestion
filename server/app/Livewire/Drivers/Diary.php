<?php

namespace App\Livewire\Drivers;

use App\Enums\DriverLogCategory;
use App\Livewire\Forms\DriverLogForm;
use App\Models\Driver;
use App\Models\DriverLog;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use OwenIt\Auditing\Models\Audit;

/**
 * Diario de incidencias de un chofer (Bloque 14): notas de oficina sobre lo que hace — bueno o
 * malo —, con quién y cuándo editó cada una si se ha tocado tras crearla.
 */
#[Layout('layouts.app')]
class Diary extends Component
{
    use WithPagination;

    public Driver $driver;

    public DriverLogForm $form;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $category = 'all';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    public ?int $historyForId = null;

    public function mount(Driver $driver): void
    {
        $this->authorize('viewAny', DriverLog::class);

        $this->driver = $driver;
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'category', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('create', DriverLog::class);

        $this->form->reset();
        $this->form->driver_id = $this->driver->id;
        $this->form->occurred_on = now()->toDateString();
        $this->dispatch('open-modal', 'log-form');
    }

    public function edit(DriverLog $log): void
    {
        $this->authorize('update', $log);

        $this->form->setLog($log);
        $this->dispatch('open-modal', 'log-form');
    }

    public function save(): void
    {
        $this->editing()
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', DriverLog::class);

        $this->form->save();

        $this->dispatch('close-modal', 'log-form');
        $this->dispatch('toast', message: 'Incidencia guardada correctamente.', variant: 'success');
    }

    public function delete(DriverLog $log): void
    {
        $this->authorize('delete', $log);

        $log->delete();

        $this->dispatch('toast', message: 'Incidencia eliminada.', variant: 'success');
    }

    public function editing(): bool
    {
        return $this->form->editing !== null;
    }

    public function viewHistory(int $logId): void
    {
        $log = DriverLog::findOrFail($logId);
        $this->authorize('view', $log);

        $this->historyForId = $logId;
        $this->dispatch('open-modal', 'log-history');
    }

    #[Computed]
    public function historyLog(): ?DriverLog
    {
        return $this->historyForId
            ? DriverLog::with(['creator', 'editor'])->find($this->historyForId)
            : null;
    }

    /** @return Collection<int, Audit> */
    #[Computed]
    public function historyAudits()
    {
        if (! $this->historyForId) {
            return collect();
        }

        return Audit::query()
            ->with('user')
            ->where('auditable_type', DriverLog::class)
            ->where('auditable_id', $this->historyForId)
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function categories(): array
    {
        return DriverLogCategory::cases();
    }

    public function render()
    {
        $logs = DriverLog::query()
            ->where('driver_id', $this->driver->id)
            ->with(['creator', 'editor'])
            ->when($this->category !== 'all', fn ($q) => $q->where('category', $this->category))
            ->when($this->search, fn ($q) => $q->where('body', 'ilike', "%{$this->search}%"))
            ->when($this->from, fn ($q) => $q->whereDate('occurred_on', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('occurred_on', '<=', $this->to))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.drivers.diary', ['logs' => $logs]);
    }
}
