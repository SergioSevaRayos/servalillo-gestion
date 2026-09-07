<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Forms\ClientForm;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\RouteStop;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use OwenIt\Auditing\Models\Audit;

#[Layout('layouts.app')]
class Show extends Component
{
    public Client $client;

    public ClientForm $form;

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

    /** Crea una parada en el backlog ("Sin asignar") con los datos del cliente. */
    public function planDelivery(): void
    {
        abort_if($this->client->isProspect(), 403, 'Convierte el pre-cliente en cliente antes de planificar.');

        $this->authorize('update', $this->client);
        abort_unless(auth()->user()->can('routes.update'), 403);

        $stop = RouteStop::create([
            'route_id' => null,
            'position' => (RouteStop::whereNull('route_id')->max('position') ?? 0) + 1,
            'service_kind' => $this->client->service_kind->value,
            'customer_name' => $this->client->name,
            'customer_tax_id' => $this->client->tax_id,
            'address' => $this->client->address,
            'latitude' => $this->client->latitude,
            'longitude' => $this->client->longitude,
            'contact_name' => $this->client->contact_name,
            'contact_phone' => $this->client->phone,
            'delivery_type_id' => $this->client->default_delivery_type_id,
            'status' => RouteStopStatus::Pending,
            'planned_quantity' => $this->client->typical_quantity,
            'data' => [],
        ]);

        $this->dispatch('toast',
            message: "{$this->client->service_kind->label()} añadido a \"Sin asignar\" (parada #{$stop->id}).",
            variant: 'success',
        );
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

        return [
            'count' => $this->history()->count(),
            'total_liters' => (float) $done->sum('delivered_quantity'),
            'last_on' => $this->history()->first()?->route?->route_date,
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
