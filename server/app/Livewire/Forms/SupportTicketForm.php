<?php

namespace App\Livewire\Forms;

use App\Enums\SupportCategory;
use App\Enums\SupportStatus;
use App\Models\SupportTicket;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Alta/edición de una incidencia de soporte por parte del administrador (Bloque 12).
 */
class SupportTicketForm extends Form
{
    public ?SupportTicket $editing = null;

    public string $subject = '';

    public ?string $category = null;

    public string $body = '';

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(SupportCategory::class)],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => 'Ponle un asunto a la incidencia.',
            'category.required' => 'Elige el tipo de incidencia.',
            'body.required' => 'Describe el fallo o la necesidad.',
        ];
    }

    public function setTicket(SupportTicket $ticket): void
    {
        $this->editing = $ticket;
        $this->subject = $ticket->subject;
        $this->category = $ticket->category->value;
        $this->body = $ticket->body;
    }

    public function save(): SupportTicket
    {
        $data = $this->validate();

        if ($this->editing) {
            $this->editing->update($data);
            $ticket = $this->editing;
        } else {
            $ticket = SupportTicket::create([
                ...$data,
                'user_id' => auth()->id(),
                'status' => SupportStatus::Abierto->value,
            ]);
        }

        $this->reset();

        return $ticket;
    }
}
