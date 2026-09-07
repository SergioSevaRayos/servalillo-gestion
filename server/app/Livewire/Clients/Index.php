<?php

namespace App\Livewire\Clients;

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

    public function save(): void
    {
        $this->form->editing
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', Client::class);

        $this->form->save();

        $this->dispatch('close-modal', 'client-form');
        $this->dispatch('toast', message: 'Cliente guardado correctamente.', variant: 'success');
    }

    public function delete(Client $client): void
    {
        $this->authorize('delete', $client);
        $client->delete();
        $this->dispatch('toast', message: 'Cliente eliminado.', variant: 'success');
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
            ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
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
