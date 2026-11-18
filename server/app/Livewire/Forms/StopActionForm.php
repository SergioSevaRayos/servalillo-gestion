<?php

namespace App\Livewire\Forms;

use App\Enums\RouteStopStatus;
use App\Models\Client;
use App\Models\RouteStop;
use App\Services\DeliveryNoteService;
use App\Services\DeliveryTypeSchemaValidator;
use App\Services\RecurringRouteService;
use App\Support\Notifications\RouteChangeNotifier;
use Illuminate\Support\Carbon;
use Livewire\Form;

/**
 * Cierre operativo de una parada por parte del chofer: entregada, fallida o cancelada.
 * Al entregar se crea el albarán y se encola su PDF/envío (ver DeliveryNoteService).
 */
class StopActionForm extends Form
{
    public ?RouteStop $stop = null;

    /** completed | failed | skipped */
    public string $outcome = 'completed';

    public ?float $delivered_quantity = null;

    /** Si esta entrega pasa por el contador de litros del camión (por defecto sí). */
    public bool $counted_in_meter = true;

    /** DNI/NIF/CIF del cliente (se precarga si ya se guardó alguna vez; ver setStop()). */
    public ?string $customer_tax_id = null;

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
        $this->counted_in_meter = $stop->counted_in_meter;
        // Si la parada no trae DNI/CIF pero el cliente ya lo tiene guardado de otra vez, sale solo.
        $this->customer_tax_id = $stop->customer_tax_id ?: $this->matchingClient($stop)?->tax_id;
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
            'counted_in_meter' => ['boolean'],
            'customer_tax_id' => ['nullable', 'regex:/^[A-Z0-9]{8,12}$/'],
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
            'customer_tax_id.regex' => 'El DNI/CIF no es válido: de 8 a 12 letras y números, sin espacios ni guiones.',
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

    /**
     * Quita espacios, puntos y guiones y pasa a mayúsculas ("12.345.678-z" → "12345678Z");
     * vacío → null. Se hace ANTES de validar, igual que ClientForm::save(): un valor con
     * separadores de sobra se limpia solo en vez de rebotar como error.
     */
    public static function normalizeTaxId(?string $value): ?string
    {
        $clean = strtoupper(preg_replace('/[\s.\-]/', '', (string) $value));

        return $clean === '' ? null : $clean;
    }

    /**
     * Cliente de la ficha que corresponde a la parada (mismo emparejamiento que
     * Chofer\Today::captureStopCoordinates(): CIF si lo hay, si no nombre exacto — no existe
     * route_stops.client_id, ver "Gestión de clientes").
     */
    private function matchingClient(RouteStop $stop): ?Client
    {
        return Client::query()
            ->when(
                $stop->customer_tax_id,
                fn ($q) => $q->where('tax_id', $stop->customer_tax_id),
                fn ($q) => $q->where('name', $stop->customer_name),
            )
            ->first();
    }

    public function apply(DeliveryTypeSchemaValidator $schemaValidator, DeliveryNoteService $notes, RouteChangeNotifier $notifier): void
    {
        $this->customer_tax_id = self::normalizeTaxId($this->customer_tax_id);

        $validated = $this->validate();
        $stop = $this->stop;

        if ($validated['outcome'] === 'completed') {
            $cleanData = $stop->delivery_type_id
                ? $schemaValidator->validate($stop->deliveryType, $this->data)
                : [];

            // DNI/CIF: se guarda en la parada (llega al albarán) y, si la ficha del cliente no
            // tenía ninguno, también ahí — así la próxima vez sale precargado. Nunca pisa uno
            // ya existente (mismo criterio que las coordenadas).
            if ($taxId = $validated['customer_tax_id'] ?? null) {
                $client = $this->matchingClient($stop);
                if ($client && blank($client->tax_id)) {
                    $client->update(['tax_id' => $taxId]);
                }
            }

            $stop->update([
                'customer_tax_id' => $validated['customer_tax_id'] ?? $stop->customer_tax_id,
                'status' => RouteStopStatus::Completed,
                'delivered_quantity' => $validated['delivered_quantity'],
                'counted_in_meter' => $validated['counted_in_meter'] ?? true,
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

            // Aviso a administración: el chofer se ha desviado del plan (Bloque 12).
            if (! empty($validated['reschedule_on'])) {
                $notifier->stopRescheduled($stop, $validated['reschedule_on']);
            } elseif ($validated['outcome'] === 'failed') {
                $notifier->stopFailed($stop);
            } else {
                $notifier->stopSkipped($stop);
            }
        }

        $this->reset();
    }

    /**
     * Crea una parada nueva (pendiente) para otro día con los datos de esta, directamente en
     * la columna de la ruta del propio chofer ese día (se genera si aún no existía — la ruta
     * permanente ya la cubre). Si por lo que sea no hay ruta permanente detrás, cae a
     * "Sin asignar" para que oficina la revise y coloque a mano.
     */
    private function rescheduleStop(RouteStop $stop, string $date): void
    {
        $permanentRoute = $stop->route?->route;
        $routeDay = $permanentRoute
            ? app(RecurringRouteService::class)->ensureForDate($permanentRoute, $date)
            : null;

        $position = RouteStop::query()->where('route_id', $routeDay?->id)->max('position');

        RouteStop::create([
            'route_id' => $routeDay?->id,
            'position' => ($position ?? 0) + 1,
            'scheduled_for' => $date,
            'rescheduled_by' => auth()->id(),
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
            'counted_in_meter' => true,
            'failure_reason' => null,
            'completed_at' => null,
        ]);

        $this->reset();
    }
}
