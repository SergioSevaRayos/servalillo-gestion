<?php

namespace App\Livewire\Forms;

use App\Enums\RouteStopStatus;
use App\Models\RouteStop;
use App\Services\DeliveryNoteService;
use App\Services\DeliveryTypeSchemaValidator;
use Livewire\Form;

/**
 * Cierre operativo de una parada por parte del chofer: entregada, fallida u omitida.
 * Al entregar se crea el albarán y se encola su PDF/envío (ver DeliveryNoteService).
 */
class StopActionForm extends Form
{
    public ?RouteStop $stop = null;

    /** completed | failed | skipped */
    public string $outcome = 'completed';

    public ?float $delivered_quantity = null;

    /** Motivo obligatorio para failed / skipped. */
    public ?string $reason = null;

    /** Valores de los campos flexibles del tipo de reparto. */
    public array $data = [];

    // --- Albarán (solo al entregar) ---
    public string $channel = 'email';

    public ?string $recipient_email = null;

    public ?string $signer_name = null;

    /** Data URL PNG de la firma (viene del canvas). */
    public ?string $signature = null;

    public function setStop(RouteStop $stop): void
    {
        $this->reset();
        $this->stop = $stop;
        $this->outcome = 'completed';
        $this->delivered_quantity = $stop->delivered_quantity !== null
            ? (float) $stop->delivered_quantity
            : ($stop->planned_quantity !== null ? (float) $stop->planned_quantity : null);
        $this->reason = $stop->failure_reason;
        $this->data = $stop->data ?: [];

        if ($note = $stop->deliveryNote) {
            $this->channel = $note->delivery_channel;
            $this->recipient_email = $note->recipient_email;
            $this->signer_name = $note->signer_name;
        }
    }

    public function rules(): array
    {
        $rules = [
            'outcome' => ['required', 'in:completed,failed,skipped'],
            'delivered_quantity' => ['nullable', 'numeric', 'min:0', 'required_if:outcome,completed'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:outcome,failed', 'required_if:outcome,skipped'],
        ];

        if ($this->outcome === 'completed' && ! $this->stop?->deliveryNote) {
            $rules['channel'] = ['required', 'string'];
            $rules['signer_name'] = ['required', 'string', 'max:120'];
            $rules['signature'] = ['required', 'string', 'starts_with:data:image/png;base64,'];
            $rules += app(DeliveryNoteService::class)->rulesForChannel($this->channel);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'delivered_quantity.required_if' => 'Indica cuántos litros se han entregado.',
            'reason.required_if' => 'Indica el motivo.',
            'signature.required' => 'Falta la firma del cliente.',
            'signature.starts_with' => 'La firma no es válida.',
            'signer_name.required' => 'Indica quién firma.',
            'recipient_email.required' => 'Indica el email del cliente para enviarle el albarán.',
        ];
    }

    public function apply(DeliveryTypeSchemaValidator $schemaValidator, DeliveryNoteService $notes): void
    {
        $validated = $this->validate();
        $stop = $this->stop;

        if ($validated['outcome'] === 'completed') {
            $cleanData = $stop->delivery_type_id
                ? $schemaValidator->validate($stop->deliveryType, $this->data)
                : [];

            $stop->update([
                'status' => RouteStopStatus::Completed,
                'delivered_quantity' => $validated['delivered_quantity'],
                'failure_reason' => null,
                'completed_at' => now(),
                'data' => $cleanData,
            ]);

            if (! $stop->deliveryNote()->exists()) {
                $notes->createForStop($stop->refresh(), [
                    'channel' => $this->channel,
                    'recipient_email' => $this->recipient_email,
                    'signer_name' => $this->signer_name,
                    'signature' => $this->signature,
                ], auth()->id());
            }
        } else {
            $stop->update([
                'status' => $validated['outcome'] === 'failed' ? RouteStopStatus::Failed : RouteStopStatus::Skipped,
                'delivered_quantity' => null,
                'failure_reason' => $validated['reason'],
                'completed_at' => null,
            ]);
        }

        $this->reset();
    }

    public function reopen(): void
    {
        $this->stop->update([
            'status' => RouteStopStatus::Pending,
            'delivered_quantity' => null,
            'failure_reason' => null,
            'completed_at' => null,
        ]);

        $this->reset();
    }
}
