<?php

use App\Livewire\Clients\Index;
use App\Livewire\Routes\Board;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Services\RecurringStopService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    // Lunes fijo para que los días de la semana sean deterministas.
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00')); // lunes
    DeliveryType::factory()->create(['name' => 'Suministro de agua', 'slug' => 'agua']);
});

afterEach(fn () => Carbon::setTestNow());

it('un cliente con días de la semana sabe cuándo le toca', function () {
    $c = Client::factory()->weekly([1, 3, 5])->create(); // L, X, V

    expect($c->isDeliveryDue())->toBeTrue()                     // hoy es lunes
        ->and($c->nextDeliveryOn()->toDateString())->toBe('2026-03-02')
        ->and($c->frequencyLabel())->toBe('L·X·V');

    Carbon::setTestNow(Carbon::parse('2026-03-03 09:00')); // martes
    expect($c->fresh()->isDeliveryDue())->toBeFalse()
        ->and($c->fresh()->nextDeliveryOn()->toDateString())->toBe('2026-03-04'); // miércoles
});

it('respeta el rango de fechas del calendario', function () {
    $c = Client::factory()->weekly([1])->create([
        'schedule_starts_on' => '2026-04-01',
        'schedule_ends_on' => '2026-06-30',
    ]);

    expect($c->isDeliveryDue())->toBeFalse();                   // marzo, aún no empieza
    expect($c->frequencyLabel())->toStartWith('L · ');

    Carbon::setTestNow(Carbon::parse('2026-04-06 09:00'));      // lunes de abril
    expect($c->fresh()->isDeliveryDue())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00'));      // lunes de julio, ya terminó
    expect($c->fresh()->isDeliveryDue())->toBeFalse();
});

it('genera la parada del cliente recurrente para el día que toca, una sola vez', function () {
    $c = Client::factory()->weekly([1])->create(['name' => 'Comunidad Lunes', 'typical_quantity' => 900]);
    Client::factory()->weekly([2])->create(['name' => 'Otro Martes']);
    Client::factory()->create(['name' => 'Sin calendario']);
    Client::factory()->weekly([1])->inactive()->create(['name' => 'Inactivo Lunes']);
    Client::factory()->weekly([1])->prospect()->create(['name' => 'Prospecto Lunes']);

    $svc = app(RecurringStopService::class);

    expect($svc->generateForDate(Carbon::parse('2026-03-02')))->toBe(1); // solo "Comunidad Lunes"
    expect($svc->generateForDate(Carbon::parse('2026-03-02')))->toBe(0); // idempotente

    $stop = RouteStop::firstWhere('customer_name', 'Comunidad Lunes');
    expect($stop)->not->toBeNull()
        ->and($stop->route_id)->toBeNull()
        ->and($stop->scheduled_for->toDateString())->toBe('2026-03-02')
        ->and((float) $stop->planned_quantity)->toBe(900.0);

    expect(RouteStop::whereIn('customer_name', ['Otro Martes', 'Sin calendario', 'Inactivo Lunes', 'Prospecto Lunes'])->count())->toBe(0);
});

it('si oficina borra la parada recurrente de un día, no se vuelve a generar ese día', function () {
    $c = Client::factory()->weekly([1])->create(['name' => 'Comunidad Lunes']); // todos los lunes
    $svc = app(RecurringStopService::class);

    // Lunes 02/03: se genera y oficina la borra desde el tablero (soft delete).
    expect($svc->generateForDate(Carbon::parse('2026-03-02')))->toBe(1);
    RouteStop::firstWhere('customer_name', 'Comunidad Lunes')->delete();

    // Cualquier refresco posterior del tablero / planificador NO la resucita.
    expect($svc->generateForDate(Carbon::parse('2026-03-02')))->toBe(0);
    expect(RouteStop::withTrashed()->where('customer_name', 'Comunidad Lunes')->count())->toBe(1);

    // Pero el lunes siguiente sí le toca: el calendario del cliente sigue vigente.
    expect($svc->generateForDate(Carbon::parse('2026-03-09')))->toBe(1);
});

it('un cliente diario (L-V) aparece ya colocado en la columna de la ruta cada uno de esos días', function () {
    $route = Route::factory()->create([
        'service_kind' => 'reparto',
        'valid_from' => '2026-01-01',
        'valid_until' => null,
    ]);
    Client::factory()->weekly([1, 2, 3, 4, 5])->create(['name' => 'Comunidad Vista Alegre']);

    // Horizonte de 4 días por delante de hoy (lunes) = lunes..viernes, los 5 días que le tocan.
    app(RecurringStopService::class)->generateHorizon(4);

    $stops = RouteStop::where('customer_name', 'Comunidad Vista Alegre')->get();
    expect($stops)->toHaveCount(5);

    foreach ($stops as $stop) {
        $day = RouteDay::where('route_id', $route->id)->whereDate('route_date', $stop->scheduled_for)->first();
        expect($day)->not->toBeNull()
            ->and($stop->route_id)->toBe($day->id);
    }
});

it('con varias rutas de reparto vigentes, la parada recurrente se queda en "Sin asignar"', function () {
    Route::factory()->create(['service_kind' => 'reparto', 'valid_from' => '2026-01-01', 'valid_until' => null]);
    Route::factory()->create(['service_kind' => 'reparto', 'valid_from' => '2026-01-01', 'valid_until' => null]);
    Client::factory()->weekly([1])->create(['name' => 'Comunidad Ambigua']);

    app(RecurringStopService::class)->generateForDate(Carbon::parse('2026-03-02'));

    expect(RouteStop::firstWhere('customer_name', 'Comunidad Ambigua')->route_id)->toBeNull();
});

it('la parada recurrente se genera para el día que le toca y sigue en "Sin asignar" cualquier día', function () {
    Client::factory()->weekly([1])->create(['name' => 'Recurrente Lunes']);

    $c = Livewire::actingAs(makeUser('administrador'))->test(Board::class);

    // "Sin asignar" no se vacía ni se filtra por el día que se esté viendo: es la misma columna
    // siempre, aunque la parada se haya generado (y tenga scheduled_for) para el lunes.
    $c->set('date', '2026-03-02')->assertSee('Recurrente Lunes'); // lunes: se genera y se ve
    $c->set('date', '2026-03-03')->assertSee('Recurrente Lunes'); // martes: sigue viéndose

    expect(RouteStop::firstWhere('customer_name', 'Recurrente Lunes')->scheduled_for->toDateString())
        ->toBe('2026-03-02');
});

it('el formulario guarda los días de la semana y el rango', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Cliente Semanal')
        ->call('toggleWeekday', 1)
        ->call('toggleWeekday', 5)
        ->call('toggleWeekday', 3)
        ->call('toggleWeekday', 3) // se desmarca
        ->assertSet('form.delivery_weekdays', [1, 5])
        ->set('form.schedule_ends_on', '2026-05-31')
        ->call('save')
        ->assertHasNoErrors();

    $c = Client::firstWhere('name', 'Cliente Semanal');
    expect($c->deliveryWeekdays())->toBe([1, 5])
        ->and($c->schedule_ends_on->toDateString())->toBe('2026-05-31');
});
