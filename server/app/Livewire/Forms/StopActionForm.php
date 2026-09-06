<?php

namespace App\Livewire\Forms;

use App\Enums\RouteStopStatus;
use App\Models\RouteStop;
use App\Services\DeliveryTypeSchemaValidator;
use Livewire\Form;

/**
 * Cierre operativo de una parada por parte del chofer: entregada, fallida u omitida.
 * No toca `delivery_notes` — el albarán (PDF, firma, envío) es del Bloque 8.
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
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', 'in:completed,failed,skipped'],
            'delivered_quantity' => ['nullable', 'numeric', 'min:0', 'required_if:outcome,completed'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:outcome,failed', 'required_if:outcome,skipped'],
        ];
    }

    public function messages(): array
    {
        return [
            'delivered_quantity.required_if' => 'Indica cuántos litros se han entregado.',
            'reason.required_if' => 'Indica el motivo.',
        ];
    }

    public function apply(DeliveryTypeSchemaValidator $schemaValidator): void
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
