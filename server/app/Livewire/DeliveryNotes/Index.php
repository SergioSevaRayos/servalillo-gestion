<?php

namespace App\Livewire\DeliveryNotes;

use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Services\DeliveryNoteService;
use App\Support\DeliveryChannels\DeliveryChannelManager;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(history: true)]
    public string $channel = 'all';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', DeliveryNote::class);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'channel', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'channel', 'from', 'to');
        $this->resetPage();
    }

    public function reprocess(DeliveryNote $note, DeliveryNoteService $service): void
    {
        $this->authorize('regenerate', $note);

        $service->reprocess($note);

        $this->dispatch('toast', message: "Albarán {$note->number} vuelto a encolar.", variant: 'success');
    }

    public function markDelivered(DeliveryNote $note): void
    {
        $this->authorize('markDelivered', $note);

        $note->forceFill([
            'status' => DeliveryNoteStatus::DeliveredPhysically,
            'delivered_at' => now(),
            'delivered_by' => auth()->id(),
        ])->save();

        $this->dispatch('toast', message: "Albarán {$note->number} marcado como entregado.", variant: 'success');
    }

    public function render(DeliveryChannelManager $channels)
    {
        $notes = DeliveryNote::query()
            ->with('routeStop.route.driver.user')
            ->when($this->search, function ($q) {
                $term = "%{$this->search}%";
                $q->where(fn ($q) => $q
                    ->where('number', 'ilike', $term)
                    ->orWhere('recipient_email', 'ilike', $term)
                    ->orWhere('customer_snapshot->name', 'ilike', $term));
            })
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->channel !== 'all', fn ($q) => $q->where('delivery_channel', $this->channel))
            ->when($this->from, fn ($q) => $q->where('issued_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to, fn ($q) => $q->where('issued_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->latest('issued_at')
            ->paginate(20);

        return view('livewire.delivery-notes.index', [
            'notes' => $notes,
            'statuses' => DeliveryNoteStatus::cases(),
            'channelOptions' => $channels->options(),
        ]);
    }
}
