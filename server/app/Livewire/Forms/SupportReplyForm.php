<?php

namespace App\Livewire\Forms;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Livewire\Form;

/**
 * Respuesta dentro del hilo de un ticket de soporte (Bloque 12). La usan tanto el
 * componente del administrador como el de mantenimiento.
 */
class SupportReplyForm extends Form
{
    public string $body = '';

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Escribe una respuesta.',
        ];
    }

    public function save(SupportTicket $ticket): SupportTicketReply
    {
        $this->validate();

        $reply = $ticket->replies()->create([
            'user_id' => auth()->id(),
            'body' => $this->body,
        ]);

        $this->reset();

        return $reply;
    }
}
