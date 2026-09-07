<?php

use App\Livewire\Clients\Index;
use App\Livewire\Routes\Board;
use App\Models\Client;
use App\Models\DeliveryType;
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

it('el tablero muestra la parada recurrente solo el día que le toca', function () {
    Client::factory()->weekly([1])->create(['name' => 'Recurrente Lunes']);

    $c = Livewire::actingAs(makeUser('administrador'))->test(Board::class);

    $c->set('date', '2026-03-02')->assertSee('Recurrente Lunes'); // lunes
    $c->set('date', '2026-03-03')->assertDontSee('Recurrente Lunes'); // martes
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
