<?php

namespace App\Livewire\Forms;

use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
use App\Models\DeliveryType;
use App\Models\RouteStop;
use App\Services\DeliveryTypeSchemaValidator;
use App\Services\RecurringRouteService;
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

    /**
     * Día para el que se planifica el servicio (sobre todo útil en "Sin asignar": sin fecha
     * aparece ahí todos los días — backlog general —, con fecha solo aparece ese día).
     */
    public ?string $scheduled_for = null;

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
            'scheduled_for' => ['nullable', 'date'],
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
        $this->scheduled_for = $stop->scheduled_for?->toDateString();
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
            // Al editar una parada solo se tocan los datos del servicio: la identidad del
            // cliente (nombre, dirección, contacto, tipo de servicio) se gestiona en su ficha.
            $this->editing->update([
                'delivery_type_id' => $validated['delivery_type_id'],
                'status' => $validated['status'],
                'planned_quantity' => $validated['planned_quantity'],
                'scheduled_for' => $validated['scheduled_for'],
                'data' => $cleanData,
            ]);
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

        // Si la parada sigue sin ruta y se le ha puesto fecha de servicio, se coloca sola en la
        // columna de esa ruta ese día — pero solo si hay una única ruta candidata (mismo tipo de
        // servicio, vigente esa fecha); con varias, no hay forma de adivinar cuál, así que se
        // queda en "Sin asignar" (ya con la fecha puesta) para que oficina la arrastre a mano.
        if ($stop->route_id === null && $validated['scheduled_for']) {
            $this->autoAssignToRouteDay($stop, $validated['scheduled_for']);
        }

        $this->reset();

        return $stop;
    }

    private function autoAssignToRouteDay(RouteStop $stop, string $date): void
    {
        $routeDay = app(RecurringRouteService::class)->findRouteDayForAutoAssign($stop->service_kind->value, $date);

        if ($routeDay === null) {
            return;
        }

        $stop->update([
            'route_id' => $routeDay->id,
            'position' => RouteStop::where('route_id', $routeDay->id)->max('position') + 1,
        ]);
    }
}
