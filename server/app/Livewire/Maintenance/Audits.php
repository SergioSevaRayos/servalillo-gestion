<?php

namespace App\Livewire\Maintenance;

use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\DeliveryType;
use App\Models\Device;
use App\Models\Driver;
use App\Models\OdometerReading;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Truck;
use App\Models\TruckAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use OwenIt\Auditing\Models\Audit;

#[Layout('layouts.app')]
class Audits extends Component
{
    use WithPagination;

    /** Modelos de negocio auditados: etiqueta => clase. */
    public const MODELS = [
        'Cliente' => Client::class,
        'Ruta' => Route::class,
        'Parada' => RouteStop::class,
        'Chofer' => Driver::class,
        'Camión' => Truck::class,
        'Asignación de camión' => TruckAssignment::class,
        'Tipo de reparto' => DeliveryType::class,
        'Albarán' => DeliveryNote::class,
        'Lectura de odómetro' => OdometerReading::class,
        'Dispositivo' => Device::class,
        'Usuario' => User::class,
    ];

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $model = 'all';

    #[Url(history: true)]
    public string $event = 'all';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Audit::class);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'model', 'event', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'model', 'event', 'from', 'to');
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('open-modal', 'audit-detail');
    }

    #[Computed]
    public function selected(): ?Audit
    {
        return $this->selectedId
            ? Audit::with('user')->find($this->selectedId)
            : null;
    }

    public function render()
    {
        $audits = Audit::query()
            ->with('user')
            ->when($this->model !== 'all' && in_array($this->model, self::MODELS, true),
                fn ($q) => $q->where('auditable_type', $this->model))
            ->when($this->event !== 'all', fn ($q) => $q->where('event', $this->event))
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to, fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->when($this->search, function ($q) {
                $term = "%{$this->search}%";
                $q->where(fn ($q) => $q
                    ->where('auditable_type', 'ilike', $term)
                    ->orWhere('tags', 'ilike', $term)
                    ->orWhereIn('user_id', User::query()
                        ->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)->pluck('id')));
            })
            ->latest()
            ->paginate(20);

        return view('livewire.maintenance.audits', [
            'audits' => $audits,
            'models' => self::MODELS,
        ]);
    }
}
