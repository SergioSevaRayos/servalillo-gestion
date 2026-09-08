<?php

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Jobs\ProcessDeliveryNote;
use App\Livewire\Chofer\Today;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Truck;
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
    $truck = Truck::factory()->create(['liter_meter' => 500000]);

    $started = ($routeOverrides['status'] ?? null) === RouteStatus::InProgress;

    $route = Route::factory()->create([
        'driver_id' => $driver->id,
        'truck_id' => $truck->id,
        'route_date' => today(),
        'status' => RouteStatus::Published,
        'liter_meter_start' => $started ? 500000 : null,
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

it('el chofer navega a otros días y ve la ruta de ese día', function () {
    [$user, $driver] = chofer();
    $ayer = today()->subDay();
    $rutaAyer = Route::factory()->status(RouteStatus::Completed)->create([
        'driver_id' => $driver->id, 'route_date' => $ayer, 'name' => 'Ruta de ayer',
    ]);

    Livewire::actingAs($user)->test(Today::class)
        ->assertSet('date', today()->toDateString())
        ->call('selectDay', $ayer->toDateString())
        ->assertSet('date', $ayer->toDateString())
        ->assertSee('Ruta de ayer')
        ->assertSee('Hoy')
        ->call('goToday')
        ->assertSet('date', today()->toDateString());
});

it('acepta el día por la URL y salta a hoy si es inválido', function () {
    [$user] = chofer();

    Livewire::actingAs($user)->withUrlParams(['date' => '2026-01-15'])
        ->test(Today::class)->assertSet('date', '2026-01-15');

    Livewire::actingAs($user)->withUrlParams(['date' => 'no-es-fecha'])
        ->test(Today::class)->assertSet('date', today()->toDateString());
});

it('no deja empezar una jornada de un día que no es hoy', function () {
    [$user, $driver] = chofer();
    $manana = today()->addDay();
    Route::factory()->status(RouteStatus::Published)->create(['driver_id' => $driver->id, 'route_date' => $manana]);

    $c = Livewire::actingAs($user)->test(Today::class)
        ->call('selectDay', $manana->toDateString())
        ->assertDontSee('wire:click="openStartDay"', escape: false)  // el botón no se pinta
        ->assertSee('Ruta planificada');

    $c->call('startDay')->assertForbidden();
});

it('empezar jornada registra la lectura del contador de litros y pone la ruta En curso', function () {
    [$user, $driver, $route, $truck] = chofer();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStartDay')
        ->assertSet('meterStart', 500000) // prellenado con la última lectura del camión
        ->set('meterStart', 500120)
        ->call('startDay')
        ->assertHasNoErrors();

    $route->refresh();
    expect($route->status)->toBe(RouteStatus::InProgress)
        ->and($route->started_at)->not->toBeNull()
        ->and($route->liter_meter_start)->toBe(500120);
});

it('el chofer busca un cliente que ha llamado y lo añade a su ruta', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress], stops: 2);
    $client = Client::factory()->create([
        'name' => 'Bar Manolo', 'city' => 'Tegueste', 'typical_quantity' => 400, 'is_active' => true,
    ]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openAddStop')
        ->set('clientSearch', 'Manolo')
        ->assertSee('Bar Manolo')
        ->assertSee('Tegueste')
        ->call('addClientStop', $client->id)
        ->assertDispatched('toast');

    $stop = RouteStop::where('route_id', $route->id)->where('customer_name', 'Bar Manolo')->first();
    expect($stop)->not->toBeNull()
        ->and($stop->position)->toBe(3) // al final, tras las 2 que ya había
        ->and((float) $stop->planned_quantity)->toBe(400.0)
        ->and($stop->status)->toBe(RouteStopStatus::Pending);
});

it('añadir un cliente tras terminar la jornada la reabre', function () {
    [$user, $driver, $route, $truck] = chofer([
        'status' => RouteStatus::Completed,
        'started_at' => now()->subHours(5),
        'completed_at' => now()->subHour(),
        'liter_meter_start' => 500000,
        'liter_meter_end' => 502000,
    ], stops: 2);
    $truck->update(['liter_meter' => 502000]);
    $client = Client::factory()->create(['name' => 'Bar Tardío', 'typical_quantity' => 300, 'is_active' => true]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openAddStop')
        ->call('addClientStop', $client->id)
        ->assertDispatched('toast');

    $route->refresh();
    expect($route->status)->toBe(RouteStatus::InProgress)
        ->and($route->completed_at)->toBeNull()
        ->and($route->liter_meter_end)->toBeNull()
        ->and((int) $route->truck->liter_meter)->toBe(500000) // el contador del camión vuelve a la lectura de inicio
        ->and(RouteStop::where('route_id', $route->id)->where('customer_name', 'Bar Tardío')->exists())->toBeTrue();
});

it('el buscador de cliente no muestra nada con menos de 2 caracteres', function () {
    [$user] = chofer(['status' => RouteStatus::InProgress]);
    Client::factory()->create(['name' => 'Cliente Buscable', 'is_active' => true]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openAddStop')
        ->set('clientSearch', 'C')
        ->assertDontSee('Cliente Buscable')
        ->set('clientSearch', 'Cliente')
        ->assertSee('Cliente Buscable');
});

it('un chofer sin ruta hoy no puede añadir clientes', function () {
    $user = makeUser('chofer');
    Driver::factory()->create(['user_id' => $user->id]);
    $client = Client::factory()->create();

    Livewire::actingAs($user)->test(Today::class)
        ->call('addClientStop', $client->id)
        ->assertStatus(404);
});

it('la búsqueda para añadir cliente no incluye pre-clientes', function () {
    [$user] = chofer(['status' => RouteStatus::InProgress]);
    Client::factory()->create(['name' => 'Aguas Reales SL', 'is_active' => true]);
    Client::factory()->prospect()->create(['name' => 'Aguas Fantasma SL']);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openAddStop')
        ->set('clientSearch', 'Aguas')
        ->assertSee('Aguas Reales SL')
        ->assertDontSee('Aguas Fantasma SL');
});

it('empezar jornada exige la lectura del contador de litros', function () {
    [$user, $driver, $route] = chofer();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStartDay')
        ->set('meterStart', null)
        ->call('startDay')
        ->assertHasErrors('meterStart');

    expect($route->fresh()->status)->toBe(RouteStatus::Published);
});

it('rechaza una lectura de inicio menor que la última registrada del camión', function () {
    [$user, $driver, $route] = chofer(); // truck->liter_meter = 500000

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStartDay')
        ->set('meterStart', 499000)
        ->call('startDay')
        ->assertHasErrors('meterStart');

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
        ->set('form.channel', 'email')
        ->set('form.recipient_email', 'cliente@example.com')
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
        ->and($stop->deliveryNote->signer_name)->toBe('El encargado')
        ->and($stop->deliveryNote->signature_path)->not->toBeNull();

    Queue::assertPushed(ProcessDeliveryNote::class);
});

it('la entrega en mano no pide firma en el teléfono', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    $stop = $route->stops()->first();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.outcome', 'completed')
        ->set('form.delivered_quantity', 400)
        ->set('form.channel', 'physical')
        ->call('saveStop')
        ->assertHasNoErrors();

    $note = $stop->fresh()->deliveryNote;
    expect($note)->not->toBeNull()
        ->and($note->delivery_channel)->toBe('physical')
        ->and($note->signature_path)->toBeNull();
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

it('el chofer reprograma una parada fallida para otro día', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);
    $stop = $route->stops->first();
    $stop->update(['customer_name' => 'Bar Central', 'planned_quantity' => 700]);

    $manana = today()->addDay()->toDateString();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.outcome', 'failed')
        ->set('form.reason', 'Nadie en el local')
        ->set('form.reschedule_on', $manana)
        ->call('saveStop')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    // la original queda como fallida y anota la reprogramación
    expect($stop->fresh()->status)->toBe(RouteStopStatus::Failed)
        ->and($stop->fresh()->failure_reason)->toContain('Reprogramada para');

    // nace una parada pendiente para el día siguiente (sin ruta ese día → "Sin asignar")
    $nueva = RouteStop::where('customer_name', 'Bar Central')
        ->where('status', RouteStopStatus::Pending)
        ->first();

    expect($nueva)->not->toBeNull()
        ->and($nueva->id)->not->toBe($stop->id)
        ->and($nueva->route_id)->toBeNull()
        ->and($nueva->scheduled_for->toDateString())->toBe($manana)
        ->and($nueva->rescheduled_by)->toBe($user->id)
        ->and((float) $nueva->planned_quantity)->toBe(700.0);

    // y le aparece al chofer ese día, en "Reprogramadas para este día"
    Livewire::actingAs($user)->test(Today::class)
        ->set('date', $manana)
        ->assertSee('Reprogramadas para este día')
        ->assertSee('Bar Central');
});

it('reprogramar a un día con ruta propia mete la parada en esa ruta', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);
    $manana = today()->addDay()->toDateString();
    $rutaManana = Route::factory()->create(['driver_id' => $driver->id, 'route_date' => $manana]);
    $stop = $route->stops->first();
    $stop->update(['customer_name' => 'Taller Gómez']);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.outcome', 'skipped')
        ->set('form.reason', 'sin acceso')
        ->set('form.reschedule_on', $manana)
        ->call('saveStop');

    $nueva = RouteStop::where('customer_name', 'Taller Gómez')->where('status', RouteStopStatus::Pending)->first();
    expect($nueva->route_id)->toBe($rutaManana->id)
        ->and($nueva->scheduled_for->toDateString())->toBe($manana);
});

it('reprogramar exige una fecha futura', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);
    $stop = $route->stops->first();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $stop->id)
        ->set('form.outcome', 'skipped')
        ->set('form.reason', 'x')
        ->set('form.reschedule_on', today()->toDateString())
        ->call('saveStop')
        ->assertHasErrors('form.reschedule_on');
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

it('terminar jornada guarda la lectura de fin, actualiza el camión y completa la ruta', function () {
    [$user, $driver, $route, $truck] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    // liter_meter_start = 500000 (helper), sin repartos → debería marcar 500000.

    Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->assertSet('meterEnd', 500000)
        ->call('endDay')
        ->assertHasNoErrors();

    $route->refresh();
    expect($route->status)->toBe(RouteStatus::Completed)
        ->and($route->liter_meter_end)->toBe(500000)
        ->and($route->liter_discrepancy_note)->toBeNull()
        ->and($truck->fresh()->liter_meter)->toBe(500000);
});

it('si el contador de litros no cuadra con lo repartido, exige un motivo del ajuste', function () {
    [$user, $driver, $route, $truck] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()]);
    // Inicio del contador 500000, una entrega de 1000 L → debería marcar 501000.
    $route->stops()->first()->update(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 1000, 'completed_at' => now()]);

    $c = Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->assertSet('meterEnd', 501000)
        ->set('meterEnd', 501004)   // marca 4 L de más
        ->call('endDay')
        ->assertHasErrors('meterNote');

    expect($route->fresh()->status)->toBe(RouteStatus::InProgress);

    $c->set('meterNote', 'Se soltó la manguera y se derramaron 4 L')
        ->call('endDay')
        ->assertHasNoErrors();

    $route->refresh();
    expect($route->status)->toBe(RouteStatus::Completed)
        ->and($route->liter_meter_end)->toBe(501004)
        ->and($route->liter_discrepancy_note)->toContain('manguera')
        ->and($truck->fresh()->liter_meter)->toBe(501004);
});

it('rechaza una lectura de fin menor que la de inicio', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now(), 'liter_meter_start' => 500000]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->set('meterEnd', 499000)
        ->call('endDay')
        ->assertHasErrors('meterEnd');

    expect($route->fresh()->status)->toBe(RouteStatus::InProgress);
});

it('un gestor no accede a la web del chofer', function () {
    $this->actingAs(makeUser('administrador'))->get('/chofer/ruta')->assertForbidden();
});

it('el chofer abre "Ver recorrido" y se emite el evento del mapa', function () {
    config()->set('servalillo.routing.enabled', false);

    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    RouteStop::factory()->for($route)->create(['position' => 1, 'customer_name' => 'Mi Parada', 'latitude' => 28.40, 'longitude' => -16.40]);
    RouteStop::factory()->for($route)->create(['position' => 2, 'latitude' => 28.42, 'longitude' => -16.42]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('showRouteMap')
        ->assertDispatched('open-route-map', fn ($event, $params) => count($params['stops']) === 2);
});

it('un chofer sin ruta no puede ver el recorrido (404)', function () {
    $user = makeUser('chofer');
    Driver::factory()->create(['user_id' => $user->id]);
    makeRoute(today()->toDateString());

    Livewire::actingAs($user)->test(Today::class)
        ->call('showRouteMap')
        ->assertStatus(404);
});

it('el chofer organiza su ruta desde una parada y se reordenan las pendientes', function () {
    config()->set('servalillo.routing.enabled', false);

    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    $a = RouteStop::factory()->for($route)->create(['position' => 1, 'latitude' => 28.40, 'longitude' => -16.40, 'planned_quantity' => 1000]);
    $far = RouteStop::factory()->for($route)->create(['position' => 2, 'latitude' => 28.46, 'longitude' => -16.46, 'planned_quantity' => 1000]);
    $near = RouteStop::factory()->for($route)->create(['position' => 3, 'latitude' => 28.42, 'longitude' => -16.42, 'planned_quantity' => 1000]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('startOptimize')
        ->call('runOptimize', (string) $a->id)
        ->assertDispatched('toast');

    // Sale desde $a: luego la más cercana ($near) y por último $far.
    expect($route->stops()->pluck('id')->all())->toBe([$a->id, $near->id, $far->id]);
});

it('el chofer organiza su ruta desde la base', function () {
    config()->set('servalillo.routing.enabled', false);
    config()->set('servalillo.base', ['latitude' => 28.39, 'longitude' => -16.39]);

    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    $a = RouteStop::factory()->for($route)->create(['position' => 1, 'latitude' => 28.40, 'longitude' => -16.40, 'planned_quantity' => 1000]);
    $far = RouteStop::factory()->for($route)->create(['position' => 2, 'latitude' => 28.46, 'longitude' => -16.46, 'planned_quantity' => 1000]);
    $near = RouteStop::factory()->for($route)->create(['position' => 3, 'latitude' => 28.42, 'longitude' => -16.42, 'planned_quantity' => 1000]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('startOptimize')
        ->call('runOptimize', 'base')
        ->assertDispatched('toast');

    // La base está junto a $a, así que el recorrido más corto es $a, $near, $far.
    expect($route->stops()->pluck('id')->all())->toBe([$a->id, $near->id, $far->id]);
});

it('"Ir a la base a repostar" reordena las pendientes desde la base sin tocar las completadas', function () {
    config()->set('servalillo.routing.enabled', false);
    config()->set('servalillo.base', ['latitude' => 28.39, 'longitude' => -16.39]);

    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    // El camión ya hizo la parada 1 (lejos de la base).
    $done = RouteStop::factory()->for($route)->create(['position' => 1, 'status' => RouteStopStatus::Completed, 'latitude' => 28.50, 'longitude' => -16.50, 'planned_quantity' => 1000]);
    $far = RouteStop::factory()->for($route)->create(['position' => 2, 'latitude' => 28.46, 'longitude' => -16.46, 'planned_quantity' => 1000]);
    $near = RouteStop::factory()->for($route)->create(['position' => 3, 'latitude' => 28.41, 'longitude' => -16.41, 'planned_quantity' => 1000]);
    $mid = RouteStop::factory()->for($route)->create(['position' => 4, 'latitude' => 28.44, 'longitude' => -16.44, 'planned_quantity' => 1000]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('optimizeFromBase')
        ->assertDispatched('toast');

    // La completada se queda la primera; las pendientes salen desde la base: cerca -> media -> lejos.
    expect($route->stops()->pluck('id')->all())->toBe([$done->id, $near->id, $mid->id, $far->id])
        ->and($done->fresh()->position)->toBe(1)
        ->and($done->fresh()->status)->toBe(RouteStopStatus::Completed);
});

it('el chofer sube y baja una parada pendiente a mano', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    $a = RouteStop::factory()->for($route)->create(['position' => 1]);
    $b = RouteStop::factory()->for($route)->create(['position' => 2]);
    $c = RouteStop::factory()->for($route)->create(['position' => 3]);

    $t = Livewire::actingAs($user)->test(Today::class);

    $t->call('moveStop', $c->id, 'up');
    expect($route->stops()->pluck('id')->all())->toBe([$a->id, $c->id, $b->id]);

    $t->call('moveStop', $a->id, 'down');
    expect($route->stops()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('mover una parada en un extremo no hace nada', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    $a = RouteStop::factory()->for($route)->create(['position' => 1]);
    $b = RouteStop::factory()->for($route)->create(['position' => 2]);

    Livewire::actingAs($user)->test(Today::class)->call('moveStop', $a->id, 'up');

    expect($route->stops()->pluck('id')->all())->toBe([$a->id, $b->id]);
});

it('mover paradas pendientes no cambia el sitio de una completada', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    $done = RouteStop::factory()->for($route)->create(['position' => 1, 'status' => RouteStopStatus::Completed]);
    $b = RouteStop::factory()->for($route)->create(['position' => 2]);
    $c = RouteStop::factory()->for($route)->create(['position' => 3]);

    Livewire::actingAs($user)->test(Today::class)->call('moveStop', $c->id, 'up');

    // La completada sigue primera; se intercambian solo $b y $c.
    expect($route->stops()->pluck('id')->all())->toBe([$done->id, $c->id, $b->id])
        ->and($done->fresh()->position)->toBe(1);
});

it('el chofer no puede mover una parada completada', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 0);
    $done = RouteStop::factory()->for($route)->create(['position' => 1, 'status' => RouteStopStatus::Completed]);
    RouteStop::factory()->for($route)->create(['position' => 2]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('moveStop', $done->id, 'down')
        ->assertStatus(422);
});

it('no se puede ir a la base a repostar con la jornada terminada', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::Completed, 'started_at' => now(), 'completed_at' => now()], stops: 3);

    Livewire::actingAs($user)->test(Today::class)
        ->call('optimizeFromBase')
        ->assertStatus(403);
});

it('no se puede organizar una ruta ya terminada', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::Completed, 'started_at' => now(), 'completed_at' => now()], stops: 3);

    Livewire::actingAs($user)->test(Today::class)
        ->call('startOptimize')
        ->assertStatus(403);
});

it('un chofer sin ruta hoy no puede organizar nada (404)', function () {
    $user = makeUser('chofer');
    Driver::factory()->create(['user_id' => $user->id]);
    // La ruta de hoy es de otro chofer.
    makeRoute(today()->toDateString());

    Livewire::actingAs($user)->test(Today::class)
        ->call('startOptimize')
        ->assertStatus(404);
});
