<?php

namespace App\Livewire\Alerts;

use App\Enums\AlertType;
use App\Livewire\Forms\AlertRulesForm;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Services\AlertEvaluator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Configuración de los avisos de flota y listado de los activos / resueltos recientemente. */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public AlertRulesForm $form;

    // --- Historial de resueltos: filtros + selección para borrar ---
    #[Url(as: 'tipo', history: true)]
    public string $historyType = 'all';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    /** @var array<int, int|string> */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('viewAny', AlertRule::class);
        $this->form->load();
    }

    public function save(): void
    {
        $this->authorize('update', new AlertRule);

        $this->form->save();

        $this->dispatch('toast', message: 'Avisos guardados.', variant: 'success');
    }

    /** Evalúa ahora mismo (sin esperar al minuto del planificador): útil al ajustar un umbral. */
    public function evaluateNow(AlertEvaluator $evaluator): void
    {
        $this->authorize('update', new AlertRule);

        $summary = $evaluator->run();
        $opened = array_sum(array_column($summary, 'opened'));

        $this->dispatch('toast',
            message: $opened ? "Evaluado: {$opened} aviso(s) nuevo(s)." : 'Evaluado: sin avisos nuevos.',
            variant: $opened ? 'warning' : 'success',
        );
    }

    public function updating($name): void
    {
        if (in_array($name, ['historyType', 'from', 'to'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function resetHistoryFilters(): void
    {
        $this->reset('historyType', 'from', 'to', 'selected');
        $this->resetPage();
    }

    /** Resueltos que cumplen los filtros. SIEMPRE solo resueltos: un aviso activo nunca se borra. */
    private function historyQuery()
    {
        return AlertEvent::query()
            ->whereNotNull('resolved_at')
            ->when($this->historyType !== 'all', fn ($q) => $q->where('rule_type', $this->historyType))
            ->when($this->from, fn ($q) => $q->where('resolved_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to, fn ($q) => $q->where('resolved_at', '<=', Carbon::parse($this->to)->endOfDay()));
    }

    /** Marca/desmarca todos los de la página visible (no toca la selección de otras páginas). */
    public function toggleSelectAll(): void
    {
        $pageIds = $this->historyQuery()->latest('resolved_at')->forPage($this->getPage(), 15)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $current = array_map('intval', $this->selected);

        $this->selected = count(array_intersect($pageIds, $current)) === count($pageIds)
            ? array_values(array_diff($current, $pageIds))
            : array_values(array_unique([...$current, ...$pageIds]));
    }

    public function deleteSelected(): void
    {
        $this->authorize('update', new AlertRule);

        $count = AlertEvent::query()->whereNotNull('resolved_at')->whereIn('id', $this->selected)->delete();
        $this->selected = [];

        $this->dispatch('toast', message: "{$count} aviso(s) eliminado(s).", variant: 'success');
    }

    /** Vacía el historial que se está viendo (respeta los filtros activos). */
    public function deleteFiltered(): void
    {
        $this->authorize('update', new AlertRule);

        $count = $this->historyQuery()->delete();
        $this->selected = [];
        $this->resetPage();

        $this->dispatch('toast', message: "{$count} aviso(s) eliminado(s).", variant: 'success');
    }

    public function render()
    {
        $days = (int) config('servalillo.alerts_retention_days');

        return view('livewire.alerts.index', [
            'types' => AlertType::cases(),
            'active' => AlertEvent::query()->open()->latest('opened_at')->get(),
            'history' => $this->historyQuery()->latest('resolved_at')->paginate(15),
            'resolvedTotal' => AlertEvent::query()->whereNotNull('resolved_at')->count(),
            'retentionDays' => $days,
        ]);
    }
}
