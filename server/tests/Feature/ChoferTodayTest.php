<?php

use App\Enums\OdometerKind;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Jobs\ProcessDeliveryNote;
use App\Livewire\Chofer\Today;
use App\Models\DeliveryType;
use App\Models\Driver;
use App\Models\OdometerReading;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Truck;
use App\Services\OdometerService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    Queue::fake();
});

/** Crea un chofer (User+Driver) y su ruta de hoy con N paradas pendientes. */
function chofer(array $routeOverrides = [], int $stops = 3): array
{
    $user = makeUser('chofer');
    $driver = Driver::factory()->create(['user_id' => $user->id]);
    $truck = Truck::factory()->create(['odometer' => 100000]);

    $route = Route::factory()->create([
        'driver_id' => $driver->id,
        'truck_id' => $truck->id,
        'route_date' => today(),
        'status' => RouteStatus::Published,
        ...$routeOverrides,
    ]);

    RouteStop::factory()->for($route)->count($stops)->sequence(fn ($s) => ['position' => $s->index + 1])
        ->create(['status' => RouteStopStatus::Pending, 'planned_quantity' => 1000]);

    return [$user, $driver, $route, $truck];
}

it('muestra la ruta de hoy del chofer y no la de otro', function () {
    [$user, $driver, $route] = chofer();
    $otra = Route::factory()->create(['route_date' => today(), 'name' => 'Ruta ajena']);

    Livewire::actingAs($user)->test(Today::class)
        ->assertSee($route->truck->code)
        ->assertDontSee('Ruta ajena');
});

it('un chofer sin ruta hoy ve el estado vacío', function () {
    $user = makeUser('chofer');
    Driver::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(Today::class)
        ->assertSee('No tienes ninguna ruta asignada para hoy');
});

it('empezar jornada registra el contador de inicio y pone la ruta En curso', function () {
    [$user, $driver, $route, $truck] = chofer();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStartDay')
        ->set('odometer', 100050)
        ->call('startDay')
        ->assertHasNoErrors();

    expect($route->fresh()->status)->toBe(RouteStatus::InProgress)
        ->and($route->fresh()->started_at)->not->toBeNull()
        ->and(OdometerReading::where('route_id', $route->id)->where('kind', 'start')->value('value'))->toBe(100050);
});

it('rechaza un contador de inicio menor que el odómetro del camión', function () {
    [$user, $driver, $route] = chofer();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStartDay')
        ->set('odometer', 90000)
        ->call('startDay')
        ->assertHasErrors('odometer');

    expect($route->fresh()->status)->toBe(RouteStatus::Published);
});

it('no deja operar una parada antes de empezar la jornada', function () {
    [$user, $driver, $route] = chofer();
    $stop = $route->stops()->first();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.delivered_quantity', 900)
        ->call('saveStop')
        ->assertStatus(403);

    expect($stop->fresh()->status)->toBe(RouteStopStatus::Pending);
});

it('completa una parada con cantidad y datos del tipo de reparto', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    $type = DeliveryType::factory()->create([
        'field_schema' => [
            ['key' => 'producto', 'label' => 'Producto', 'type' => 'select', 'required' => true, 'options' => ['Gasóleo A', 'Gasóleo B']],
        ],
    ]);
    $stop = $route->stops()->first();
    $stop->update(['delivery_type_id' => $type->id]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.outcome', 'completed')
        ->set('form.delivered_quantity', 950)
        ->set('form.data.producto', 'Gasóleo A')
        ->set('form.channel', 'physical')
        ->set('form.signer_name', 'El encargado')
        ->set('form.signature', fakeSignature())
        ->call('saveStop')
        ->assertHasNoErrors();

    $stop->refresh();
    expect($stop->status)->toBe(RouteStopStatus::Completed)
        ->and((float) $stop->delivered_quantity)->toBe(950.0)
        ->and($stop->data['producto'])->toBe('Gasóleo A')
        ->and($stop->completed_at)->not->toBeNull()
        ->and($stop->deliveryNote)->not->toBeNull()
        ->and($stop->deliveryNote->signer_name)->toBe('El encargado');

    Queue::assertPushed(ProcessDeliveryNote::class);
});

it('al entregar por email exige el email del cliente y la firma', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    $stop = $route->stops()->first();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.outcome', 'completed')
        ->set('form.delivered_quantity', 500)
        ->set('form.channel', 'email')
        ->set('form.recipient_email', '')
        ->set('form.signer_name', 'Alguien')
        ->set('form.signature', '')
        ->call('saveStop')
        ->assertHasErrors(['form.recipient_email', 'form.signature']);

    expect($stop->fresh()->status)->toBe(RouteStopStatus::Pending);
});

it('exige cantidad al completar y motivo al fallar/omitir', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    $stop = $route->stops()->first();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.outcome', 'completed')
        ->set('form.delivered_quantity', null)
        ->call('saveStop')
        ->assertHasErrors('form.delivered_quantity')
        ->set('form.outcome', 'failed')
        ->set('form.reason', '')
        ->call('saveStop')
        ->assertHasErrors('form.reason');
});

it('marca una parada como fallida y como omitida con motivo', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    [$a, $b] = $route->stops;

    $c = Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $a->id)->set('form.outcome', 'failed')->set('form.reason', 'Cliente ausente')->call('saveStop')
        ->call('openStop', $b->id)->set('form.outcome', 'skipped')->set('form.reason', 'Lo dejamos para mañana')->call('saveStop');

    expect($a->fresh()->status)->toBe(RouteStopStatus::Failed)
        ->and($a->fresh()->failure_reason)->toBe('Cliente ausente')
        ->and($b->fresh()->status)->toBe(RouteStopStatus::Skipped);
});

it('permite reabrir una parada cerrada', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    $stop = $route->stops()->first();
    $stop->update(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 500, 'completed_at' => now()]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->call('reopenStop')
        ->assertHasNoErrors();

    expect($stop->fresh()->status)->toBe(RouteStopStatus::Pending)
        ->and($stop->fresh()->delivered_quantity)->toBeNull();
});

it('terminar jornada registra el contador de fin, actualiza el camión y completa la ruta', function () {
    [$user, $driver, $route, $truck] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    OdometerReading::create(['route_id' => $route->id, 'truck_id' => $truck->id, 'driver_id' => $driver->id, 'kind' => OdometerKind::Start->value, 'value' => 100000, 'recorded_at' => now()]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->set('odometer', 100180)
        ->call('endDay')
        ->assertHasNoErrors();

    expect($route->fresh()->status)->toBe(RouteStatus::Completed)
        ->and($truck->fresh()->odometer)->toBe(100180)
        ->and(OdometerReading::where('route_id', $route->id)->where('kind', 'end')->value('value'))->toBe(100180);
});

it('el modal de terminar jornada se prellena con la lectura de inicio', function () {
    [$user, $driver, $route, $truck] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    // El inicio (143323) es mayor que el odómetro guardado del camión (100000).
    OdometerReading::create(['route_id' => $route->id, 'truck_id' => $truck->id, 'driver_id' => $driver->id, 'kind' => OdometerKind::Start->value, 'value' => 143323, 'recorded_at' => now()]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->assertSet('odometer', 143323);
});

it('rechaza un contador de fin menor que el de inicio', function () {
    [$user, $driver, $route, $truck] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    OdometerReading::create(['route_id' => $route->id, 'truck_id' => $truck->id, 'driver_id' => $driver->id, 'kind' => OdometerKind::Start->value, 'value' => 100000, 'recorded_at' => now()]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->set('odometer', 99000)
        ->call('endDay')
        ->assertHasErrors('odometer');

    expect($route->fresh()->status)->toBe(RouteStatus::InProgress);
});

it('un gestor no accede a la web del chofer', function () {
    $this->actingAs(makeUser('administrador'))->get('/chofer/ruta')->assertForbidden();
});

it('OdometerService::recordEnd fija el odómetro del camión', function () {
    $truck = Truck::factory()->create(['odometer' => 5000]);
    $route = Route::factory()->create(['truck_id' => $truck->id]);

    app(OdometerService::class)->recordEnd($route, 5300);

    expect($truck->fresh()->odometer)->toBe(5300);
});
