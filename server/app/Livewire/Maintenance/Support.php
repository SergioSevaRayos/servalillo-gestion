<?php

namespace App\Livewire\Maintenance;

use App\Enums\SupportCategory;
use App\Enums\SupportStatus;
use App\Livewire\Forms\SupportReplyForm;
use App\Models\SupportTicket;
use App\Support\Notifications\SupportNotifier;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Canal de soporte, lado mantenimiento (Bloque 12): 4ª pestaña de /mantenimiento.
 * Ve todas las incidencias, filtra, responde, cambia el estado y borra.
 */
#[Layout('layouts.app')]
class Support extends Component
{
    use WithPagination;

    public SupportReplyForm $replyForm;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(history: true)]
    public string $category = 'all';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    #[Url(as: 'ticket', history: true)]
    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->authorize('manage', SupportTicket::class);

        if ($this->selectedId) {
            $this->dispatch('open-modal', 'support-thread');
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'category', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'category', 'from', 'to');
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->selectedId = $id;
        $this->replyForm->reset();
        $this->dispatch('open-modal', 'support-thread');
    }

    #[Computed]
    public function selected(): ?SupportTicket
    {
        return $this->selectedId
            ? SupportTicket::with(['creator', 'replies.author'])->find($this->selectedId)
            : null;
    }

    public function reply(): void
    {
        $ticket = $this->selected;
        abort_unless($ticket, 404);
        $this->authorize('reply', $ticket);

        $reply = $this->replyForm->save($ticket);
        $ticket->markReplied();
        app(SupportNotifier::class)->replied($ticket, $reply);

        unset($this->selected);
        $this->dispatch('toast', message: 'Respuesta enviada.', variant: 'success');
    }

    public function setStatus(string $value): void
    {
        $ticket = $this->selected;
        abort_unless($ticket, 404);
        $this->authorize('manage', $ticket);

        $new = SupportStatus::from($value);
        $old = $ticket->status;

        if ($old === $new) {
            return;
        }

        $ticket->update(['status' => $new->value]);
        app(SupportNotifier::class)->statusChanged($ticket, $old, $new);

        unset($this->selected);
        $this->dispatch('toast', message: 'Estado actualizado.', variant: 'success');
    }

    public function deleteTicket(int $id): void
    {
        $ticket = SupportTicket::findOrFail($id);
        $this->authorize('delete', $ticket);

        $ticket->delete();
        $this->selectedId = null;

        $this->dispatch('close-modal', 'support-thread');
        $this->dispatch('toast', message: 'Incidencia eliminada.', variant: 'success');
    }

    public function render()
    {
        $tickets = SupportTicket::query()
            ->with('creator')
            ->search($this->search)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->category !== 'all', fn ($q) => $q->where('category', $this->category))
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to, fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->orderByRaw('COALESCE(last_reply_at, created_at) DESC')
            ->paginate(20);

        return view('livewire.maintenance.support', [
            'tickets' => $tickets,
            'categories' => SupportCategory::options(),
            'statuses' => SupportStatus::options(),
        ]);
    }
}
