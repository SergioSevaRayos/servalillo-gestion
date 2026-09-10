<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\ServiceKind;
use App\Livewire\Forms\ClientForm;
use App\Models\Client;
use App\Models\DeliveryType;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public ClientForm $form;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /** all | active | inactive (todos = clientes) | prospect (pendiente valoración) */
    #[Url(history: true)]
    public string $status = 'all';

    #[Url(history: true)]
    public string $type = 'all';

    /** all | reparto | viaje */
    #[Url(history: true)]
    public string $kind = 'all';

    /** all | due (le toca reparto) */
    #[Url(history: true)]
    public string $schedule = 'all';

    #[Url(history: true)]
    public string $sort = 'name';

    #[Url(history: true)]
    public string $direction = 'asc';

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'type', 'kind', 'schedule'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'type', 'kind', 'schedule');
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        [$this->sort, $this->direction] = $this->sort === $field
            ? [$field, $this->direction === 'asc' ? 'desc' : 'asc']
            : [$field, 'asc'];
    }

    public function create(): void
    {
        $this->authorize('create', Client::class);
        $this->form->reset();
        $this->dispatch('open-modal', 'client-form');
    }

    public function edit(Client $client): void
    {
        $this->authorize('update', $client);
        $this->form->setClient($client);
        $this->dispatch('open-modal', 'client-form');
    }

    /** Marca/desmarca un día de reparto fijo en el formulario. */
    public function toggleWeekday(int $day): void
    {
        $this->form->toggleWeekday($day);
    }

    public function save(): void
    {
        $this->form->editing
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', Client::class);

        $wasNewProspect = ! $this->form->editing && $this->form->status === 'prospect';

        $client = $this->form->save();

        $this->dispatch('close-modal', 'client-form');

        if ($wasNewProspect) {
            $this->dispatch('open-prospect-summary', text: $client->prospectSummary());
        }

        $this->dispatch('toast',
            message: $wasNewProspect ? 'Pre-cliente registrado. Queda pendiente de valoración.' : 'Cliente guardado correctamente.',
            variant: 'success',
        );
    }

    public function delete(Client $client): void
    {
        $this->authorize('delete', $client);
        $client->delete();
        $this->dispatch('toast', message: 'Cliente eliminado.', variant: 'success');
    }

    /** Aprueba un pre-cliente: pasa a cliente real y abre el modal para completar la ficha. */
    public function approve(Client $client): void
    {
        $this->authorize('approve', $client);
        abort_unless($client->isProspect(), 404);

        $client->update(['status' => ClientStatus::Customer]);

        $this->form->setClient($client->refresh());
        $this->dispatch('open-modal', 'client-form');
        $this->dispatch('toast', message: 'Pre-cliente aprobado. Completa la ficha.', variant: 'success');
    }

    /** Descarta un pre-cliente no viable: borrado permanente. */
    public function discard(Client $client): void
    {
        $this->authorize('delete', $client);
        abort_unless($client->isProspect(), 404);

        $client->forceDelete();
        $this->dispatch('toast', message: 'Pre-cliente descartado.', variant: 'success');
    }

    #[Computed]
    public function deliveryTypes()
    {
        return DeliveryType::orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        $sortable = ['name', 'city', 'phone', 'typical_quantity', 'frequency_days', 'last_served_on', 'is_active'];

        $clients = Client::query()
            ->search($this->search)
            ->when($this->status === 'prospect',
                fn ($q) => $q->where('status', ClientStatus::Prospect->value),
                fn ($q) => $q->customers()->when(
                    in_array($this->status, ['active', 'inactive'], true),
                    fn ($q) => $q->where('is_active', $this->status === 'active')))
            ->when($this->type !== 'all', fn ($q) => $q->where('client_type', $this->type))
            ->when($this->kind !== 'all', fn ($q) => $q->where('service_kind', $this->kind))
            ->when($this->schedule === 'due', fn ($q) => $q
                ->whereNotNull('last_served_on')
                ->whereNotNull('frequency_days')
                ->whereRaw("last_served_on + (frequency_days || ' days')::interval <= now()"))
            ->when(in_array($this->sort, $sortable, true),
                fn ($q) => $q->orderBy($this->sort, $this->direction),
                fn ($q) => $q->orderBy('name'))
            ->paginate(15);

        return view('livewire.clients.index', [
            'clients' => $clients,
            'types' => ClientType::options(),
            'serviceKinds' => ServiceKind::options(),
        ]);
    }
}
