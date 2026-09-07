<?php

namespace App\Livewire\Forms;

use App\Enums\RouteStopStatus;
use App\Models\Route;
use App\Models\RouteStop;
use App\Services\DeliveryNoteService;
use App\Services\DeliveryTypeSchemaValidator;
use Illuminate\Support\Carbon;
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

    /** Fecha a la que reprogramar la parada (opcional, solo con failed / skipped). */
    public ?string $reschedule_on = null;

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
            'reschedule_on' => ['nullable', 'date', 'after:today'],
        ];

        if ($this->outcome === 'completed' && ! $this->stop?->deliveryNote) {
            $rules['channel'] = ['required', 'string'];
            $rules += app(DeliveryNoteService::class)->rulesForChannel($this->channel);

            if ($this->channelRequiresSignature()) {
                // Email: el cliente firma en el teléfono.
                $rules['signer_name'] = ['required', 'string', 'max:120'];
                $rules['signature'] = ['required', 'string', 'starts_with:data:image/png;base64,'];
            } else {
                // Entrega en mano: la firma va en el albarán de papel; "recibido por" es opcional.
                $rules['signer_name'] = ['nullable', 'string', 'max:120'];
            }
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

    public function channelRequiresSignature(): bool
    {
        return app(DeliveryNoteService::class)->channelRequiresSignature($this->channel);
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
                $notes->createForStop($stop->refresh(), array_filter([
                    'channel' => $this->channel,
                    'recipient_email' => $this->recipient_email,
                    'signer_name' => $this->signer_name,
                    'signature' => $this->signature ?: null,
                ], fn ($v) => $v !== null), auth()->id());
            }
        } else {
            $reason = $validated['reason'];

            if (! empty($validated['reschedule_on'])) {
                $this->rescheduleStop($stop, $validated['reschedule_on']);
                $reason = trim($reason.' · Reprogramada para '.Carbon::parse($validated['reschedule_on'])->format('d/m/Y'));
            }

            $stop->update([
                'status' => $validated['outcome'] === 'failed' ? RouteStopStatus::Failed : RouteStopStatus::Skipped,
                'delivered_quantity' => null,
                'failure_reason' => $reason,
                'completed_at' => null,
            ]);
        }

        $this->reset();
    }

    /**
     * Crea una parada nueva (pendiente) para otro día con los datos de esta.
     * Va a la ruta del mismo chofer para esa fecha si existe; si no, a "Sin asignar".
     */
    private function rescheduleStop(RouteStop $stop, string $date): void
    {
        $driverId = $stop->route?->driver_id;

        $targetRoute = $driverId
            ? Route::where('driver_id', $driverId)->whereDate('route_date', $date)->orderByDesc('id')->first()
            : null;

        $position = RouteStop::query()
            ->when($targetRoute, fn ($q) => $q->where('route_id', $targetRoute->id), fn ($q) => $q->whereNull('route_id'))
            ->max('position');

        RouteStop::create([
            'route_id' => $targetRoute?->id,
            'position' => ($position ?? 0) + 1,
            'scheduled_for' => $targetRoute ? null : $date,
            'service_kind' => $stop->service_kind->value,
            'customer_name' => $stop->customer_name,
            'customer_tax_id' => $stop->customer_tax_id,
            'address' => $stop->address,
            'latitude' => $stop->latitude,
            'longitude' => $stop->longitude,
            'contact_name' => $stop->contact_name,
            'contact_phone' => $stop->contact_phone,
            'delivery_type_id' => $stop->delivery_type_id,
            'status' => RouteStopStatus::Pending,
            'planned_quantity' => $stop->planned_quantity,
            'data' => [],
        ]);
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
