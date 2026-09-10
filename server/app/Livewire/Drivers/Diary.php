<?php

namespace App\Livewire\Drivers;

use App\Enums\DriverLogCategory;
use App\Livewire\Forms\DriverLogForm;
use App\Models\Driver;
use App\Models\DriverLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

    /**
     * Solo los campos con contenido real (no `id`/`driver_id`/`created_by`/`updated_by`…, que no
     * le dicen nada a oficina) y en español. También descarta el evento `created` — ya lo dice
     * la cabecera del modal ("Creada por…") — y cualquier `updated` que, una vez filtrado, se
     * quede sin cambios que mostrar (p. ej. si lo único tocado fue `updated_by`).
     *
     * @var array<string, string>
     */
    private const HISTORY_FIELDS = [
        'occurred_on' => 'Fecha',
        'category' => 'Categoría',
        'body' => 'Anotación',
    ];

    /** @return list<array{user: string, when: string, changes: list<array{label: string, old: string, new: string}>}> */
    #[Computed]
    public function historyEntries(): array
    {
        if (! $this->historyForId) {
            return [];
        }

        return Audit::query()
            ->with('user')
            ->where('auditable_type', DriverLog::class)
            ->where('auditable_id', $this->historyForId)
            ->where('event', 'updated')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Audit $audit) {
                $changes = collect($audit->getModified())
                    ->only(array_keys(self::HISTORY_FIELDS))
                    ->map(fn ($values, $field) => [
                        'label' => self::HISTORY_FIELDS[$field],
                        'old' => $this->formatHistoryValue($field, $values['old'] ?? null),
                        'new' => $this->formatHistoryValue($field, $values['new'] ?? null),
                    ])
                    ->values();

                if ($changes->isEmpty()) {
                    return null;
                }

                return [
                    'user' => $audit->user?->name ?? 'Sistema',
                    'when' => $audit->created_at->format('d/m/Y H:i'),
                    'changes' => $changes->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function formatHistoryValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($value instanceof DriverLogCategory) {
            return $value->label();
        }

        if ($field === 'occurred_on') {
            return Carbon::parse($value)->format('d/m/Y');
        }

        return Str::limit((string) $value, 120);
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
