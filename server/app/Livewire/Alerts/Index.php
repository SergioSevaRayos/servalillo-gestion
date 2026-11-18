<?php

namespace App\Livewire\Alerts;

use App\Enums\AlertType;
use App\Livewire\Forms\AlertRulesForm;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Services\AlertEvaluator;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Configuración de los avisos de flota y listado de los activos / resueltos recientemente. */
#[Layout('layouts.app')]
class Index extends Component
{
    public AlertRulesForm $form;

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

    public function render()
    {
        return view('livewire.alerts.index', [
            'types' => AlertType::cases(),
            'active' => AlertEvent::query()->open()->latest('opened_at')->get(),
            'recent' => AlertEvent::query()->whereNotNull('resolved_at')->latest('resolved_at')->limit(15)->get(),
        ]);
    }
}
