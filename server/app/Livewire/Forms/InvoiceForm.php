<?php

namespace App\Livewire\Forms;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Edición del control de una factura por administración: estado, nº que le han puesto
 * (la factura se hace fuera de la app) y notas.
 */
class InvoiceForm extends Form
{
    public ?Invoice $invoice = null;

    public string $status = 'pending';

    public ?string $invoice_number = null;

    public ?string $notes = null;

    public function setInvoice(Invoice $invoice): void
    {
        $this->invoice = $invoice;
        $this->status = $invoice->status->value;
        $this->invoice_number = $invoice->invoice_number;
        $this->notes = $invoice->notes;
        $this->resetErrorBag();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(InvoiceStatus::class)],
            'invoice_number' => [
                'nullable', 'string', 'max:40',
                // Emitida o enviada sin número no tiene sentido: hay que anotar el de la factura.
                Rule::requiredIf(fn () => in_array($this->status, ['issued', 'sent'], true)),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_number.required' => 'Anota el número de la factura para marcarla como emitida o enviada.',
        ];
    }

    public function save(): Invoice
    {
        // '' → null, igual que el resto de formularios (evita guardar cadenas vacías).
        $this->invoice_number = filled(trim((string) $this->invoice_number)) ? trim($this->invoice_number) : null;
        $this->notes = filled(trim((string) $this->notes)) ? trim($this->notes) : null;

        $data = $this->validate();
        $status = InvoiceStatus::from($data['status']);
        $invoice = $this->invoice;

        $invoice->fill([
            'status' => $status,
            'invoice_number' => $data['invoice_number'],
            'notes' => $data['notes'],
            'handled_by' => auth()->id(),
            // Las marcas de tiempo siguen al estado: se estampan la primera vez y se limpian si se retrocede.
            'issued_at' => $status === InvoiceStatus::Pending ? null : ($invoice->issued_at ?? now()),
            'sent_at' => $status === InvoiceStatus::Sent ? ($invoice->sent_at ?? now()) : null,
        ])->save();

        return $invoice;
    }
}
