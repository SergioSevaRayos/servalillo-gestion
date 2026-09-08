<?php

namespace App\Livewire\Support;

use App\Enums\SupportCategory;
use App\Enums\SupportStatus;
use App\Livewire\Forms\SupportReplyForm;
use App\Livewire\Forms\SupportTicketForm;
use App\Models\SupportTicket;
use App\Support\Notifications\SupportNotifier;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Canal de soporte, lado administrador (Bloque 12): abre incidencias hacia mantenimiento,
 * sigue el hilo y responde. Puede editar/borrar sus mensajes mientras mantenimiento no
 * haya respondido.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public SupportTicketForm $form;

    public SupportReplyForm $replyForm;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(as: 'ticket', history: true)]
    public ?int $selectedId = null;

    /** Id de la respuesta que se está editando, o 0 para el cuerpo del ticket, o null. */
    public ?int $editingReplyId = null;

    public string $editBody = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SupportTicket::class);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function compose(): void
    {
        $this->authorize('create', SupportTicket::class);
        $this->form->reset();
        $this->dispatch('open-modal', 'ticket-form');
    }

    public function save(): void
    {
        $this->authorize('create', SupportTicket::class);

        $ticket = $this->form->save();
        app(SupportNotifier::class)->opened($ticket);

        $this->selectedId = $ticket->id;
        $this->dispatch('close-modal', 'ticket-form');
        $this->dispatch('toast', message: 'Incidencia enviada.', variant: 'success');
    }

    public function select(int $id): void
    {
        $ticket = SupportTicket::findOrFail($id);
        $this->authorize('view', $ticket);

        $this->selectedId = $id;
        $this->editingReplyId = null;
        $this->editBody = '';
    }

    #[Computed]
    public function selected(): ?SupportTicket
    {
        return $this->selectedId
            ? SupportTicket::with(['creator', 'replies.author'])
                ->where('user_id', auth()->id())
                ->find($this->selectedId)
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

    public function startEdit(?int $replyId = null): void
    {
        $ticket = $this->selected;
        abort_unless($ticket, 404);
        $this->authorize('update', $ticket);

        $this->editingReplyId = $replyId ?? 0;
        $this->editBody = $replyId
            ? (string) $ticket->replies->firstWhere('id', $replyId)?->body
            : $ticket->body;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingReplyId', 'editBody');
    }

    public function saveEdit(): void
    {
        $ticket = $this->selected;
        abort_unless($ticket, 404);
        $this->authorize('update', $ticket);

        $this->validate(
            ['editBody' => ['required', 'string', 'max:5000']],
            ['editBody.required' => 'El mensaje no puede quedar vacío.'],
        );

        if ($this->editingReplyId) {
            $ticket->replies()
                ->where('id', $this->editingReplyId)
                ->where('user_id', auth()->id())
                ->firstOrFail()
                ->update(['body' => $this->editBody]);
        } else {
            $ticket->update(['body' => $this->editBody]);
        }

        $this->reset('editingReplyId', 'editBody');
        unset($this->selected);
        $this->dispatch('toast', message: 'Mensaje actualizado.', variant: 'success');
    }

    public function deleteReply(int $id): void
    {
        $ticket = $this->selected;
        abort_unless($ticket, 404);
        $this->authorize('update', $ticket);

        $ticket->replies()->where('id', $id)->where('user_id', auth()->id())->delete();

        unset($this->selected);
        $this->dispatch('toast', message: 'Mensaje eliminado.', variant: 'success');
    }

    public function deleteTicket(int $id): void
    {
        $ticket = SupportTicket::findOrFail($id);
        $this->authorize('delete', $ticket);

        $ticket->delete();

        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }

        $this->dispatch('toast', message: 'Incidencia eliminada.', variant: 'success');
    }

    public function render()
    {
        $tickets = SupportTicket::query()
            ->where('user_id', auth()->id())
            ->search($this->search)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->orderByRaw('COALESCE(last_reply_at, created_at) DESC')
            ->paginate(10);

        return view('livewire.support.index', [
            'tickets' => $tickets,
            'categories' => SupportCategory::options(),
            'statuses' => SupportStatus::options(),
        ]);
    }
}
