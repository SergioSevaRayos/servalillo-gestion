<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Forms\ClientForm;
use App\Livewire\Forms\ClientPlanForm;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\Route;
use App\Models\RouteStop;
use App\Services\ClientDeliverySuspender;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use OwenIt\Auditing\Models\Audit;

#[Layout('layouts.app')]
class Show extends Component
{
    public Client $client;

    public ClientForm $form;

    public ClientPlanForm $planForm;

    public function mount(Client $client): void
    {
        $this->authorize('view', $client);
        $this->client = $client;
    }

    public function edit(): void
    {
        $this->authorize('update', $this->client);
        $this->form->setClient($this->client);
        $this->dispatch('open-modal', 'client-form');
    }

    /** Marca/desmarca un día de reparto fijo en el formulario. */
    public function toggleWeekday(int $day): void
    {
        $this->form->toggleWeekday($day);
    }

    public function save(): void
    {
        $this->authorize('update', $this->client);
        $this->form->save();
        $this->client->refresh();

        $this->dispatch('close-modal', 'client-form');
        $this->dispatch('toast', message: 'Cliente actualizado.', variant: 'success');
    }

    /** Aprueba el pre-cliente y abre el modal para completar la ficha. */
    public function approve(): void
    {
        $this->authorize('approve', $this->client);
        abort_unless($this->client->isProspect(), 404);

        $this->client->update(['status' => ClientStatus::Customer]);
        $this->client->refresh();

        $this->form->setClient($this->client);
        $this->dispatch('open-modal', 'client-form');
        $this->dispatch('toast', message: 'Pre-cliente aprobado. Completa la ficha.', variant: 'success');
    }

    /** Descarta el pre-cliente no viable: borrado permanente. */
    public function discard(): void
    {
        $this->authorize('delete', $this->client);
        abort_unless($this->client->isProspect(), 404);

        $this->client->forceDelete();

        $this->dispatch('toast', message: 'Pre-cliente descartado.', variant: 'success');

        $this->redirect(route('clients.index'), navigate: true);
    }

    /** Abre el modal de "Planificar reparto" (ruta + rango de fechas + días de la semana). */
    public function openPlanDelivery(): void
    {
        abort_if($this->client->isProspect(), 403, 'Convierte el pre-cliente en cliente antes de planificar.');

        $this->authorize('update', $this->client);
        abort_unless(auth()->user()->can('routes.update'), 403);

        $this->planForm->setClient($this->client);
        $this->dispatch('open-modal', 'client-plan');
    }

    public function togglePlanWeekday(int $day): void
    {
        $this->planForm->toggleWeekday($day);
    }

    public function savePlan(): void
    {
        $this->authorize('update', $this->client);
        abort_unless(auth()->user()->can('routes.update'), 403);

        $created = $this->planForm->save();

        $this->dispatch('close-modal', 'client-plan');
        $this->dispatch('toast',
            message: $created > 0 ? "{$created} paradas planificadas." : 'No se ha creado ninguna parada nueva (ya existían para esas fechas).',
            variant: $created > 0 ? 'success' : 'warning',
        );
    }

    /**
     * Apaga el calendario recurrente del cliente y cancela de golpe todas sus paradas
     * pendientes (hoy incluido) — ver App\Services\ClientDeliverySuspender.
     */
    public function suspendAllDeliveries(): void
    {
        $this->authorize('update', $this->client);
        abort_unless(auth()->user()->can('routes.update'), 403);

        $cancelled = app(ClientDeliverySuspender::class)->suspend($this->client);
        $this->client->refresh();
        unset($this->pendingStopsCount);

        $this->dispatch('toast',
            message: $cancelled > 0
                ? "Repartos suspendidos: {$cancelled} paradas pendientes canceladas y calendario desactivado."
                : 'Calendario de reparto desactivado (no había paradas pendientes).',
            variant: 'success',
        );
    }

    #[Computed]
    public function pendingStopsCount(): int
    {
        return app(ClientDeliverySuspender::class)->pendingStopsCount($this->client);
    }

    /** Rutas permanentes candidatas: mismo tipo de servicio que el cliente. */
    #[Computed]
    public function planRoutes(): Collection
    {
        return Route::query()
            ->where('service_kind', $this->client->service_kind->value)
            ->with(['truck', 'driver.user'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function history()
    {
        return $this->client->pastStops()->limit(50)->get();
    }

    #[Computed]
    public function stats(): array
    {
        $done = $this->history()->where('status', RouteStopStatus::Completed);
        $withDwell = $this->history()->filter(fn (RouteStop $s) => $s->onSiteSeconds() !== null);

        return [
            'count' => $this->history()->count(),
            'total_liters' => (float) $done->sum('delivered_quantity'),
            'last_on' => $this->history()->first()?->route?->route_date,
            'avg_on_site_seconds' => $withDwell->isEmpty()
                ? null
                : (int) round($withDwell->avg(fn (RouteStop $s) => $s->onSiteSeconds())),
        ];
    }

    #[Computed]
    public function audits()
    {
        return Audit::query()
            ->where('auditable_type', Client::class)
            ->where('auditable_id', $this->client->id)
            ->with('user')
            ->latest()
            ->limit(15)
            ->get();
    }

    public function render()
    {
        return view('livewire.clients.show', [
            'deliveryTypes' => DeliveryType::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
