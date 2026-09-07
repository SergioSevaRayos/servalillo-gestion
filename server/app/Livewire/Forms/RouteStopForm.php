<?php

namespace App\Livewire\Forms;

use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
use App\Models\DeliveryType;
use App\Models\RouteStop;
use App\Services\DeliveryTypeSchemaValidator;
use Illuminate\Validation\Rule;
use Livewire\Form;

class RouteStopForm extends Form
{
    public ?RouteStop $editing = null;

    /** route_id de la columna en la que se está creando/editando (null = "Sin asignar"). */
    public ?int $route_id = null;

    public string $service_kind = 'reparto';

    public string $customer_name = '';

    public ?string $address = null;

    public ?string $contact_name = null;

    public ?string $contact_phone = null;

    public ?int $delivery_type_id = null;

    public string $status = 'pending';

    public ?float $planned_quantity = null;

    /** Valores de los campos flexibles del tipo de reparto elegido. */
    public array $data = [];

    public function rules(): array
    {
        return [
            'service_kind' => ['required', Rule::enum(ServiceKind::class)],
            'customer_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'delivery_type_id' => ['nullable', 'exists:delivery_types,id'],
            'status' => ['required', Rule::enum(RouteStopStatus::class)],
            'planned_quantity' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function setStop(RouteStop $stop): void
    {
        $this->editing = $stop;
        $this->route_id = $stop->route_id;
        $this->service_kind = $stop->service_kind->value;
        $this->customer_name = $stop->customer_name;
        $this->address = $stop->address;
        $this->contact_name = $stop->contact_name;
        $this->contact_phone = $stop->contact_phone;
        $this->delivery_type_id = $stop->delivery_type_id;
        $this->status = $stop->status->value;
        $this->planned_quantity = $stop->planned_quantity ? (float) $stop->planned_quantity : null;
        $this->data = $stop->data ?? [];
    }

    public function forColumn(?int $routeId, string $serviceKind = 'reparto'): void
    {
        $this->reset();
        $this->route_id = $routeId;
        $this->service_kind = $serviceKind;
    }

    public function save(DeliveryTypeSchemaValidator $schemaValidator): RouteStop
    {
        $validated = $this->validate();

        $cleanData = [];
        if ($validated['delivery_type_id']) {
            $type = DeliveryType::findOrFail($validated['delivery_type_id']);
            $cleanData = $schemaValidator->validate($type, $this->data);
        }

        if ($this->editing) {
            $this->editing->update([...$validated, 'data' => $cleanData]);
            $stop = $this->editing;
        } else {
            $nextPosition = RouteStop::where('route_id', $this->route_id)->max('position') + 1;

            $stop = RouteStop::create([
                ...$validated,
                'route_id' => $this->route_id,
                'position' => $nextPosition,
                'data' => $cleanData,
            ]);
        }

        $this->reset();

        return $stop;
    }
}
