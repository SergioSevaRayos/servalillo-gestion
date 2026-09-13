<?php

use App\Livewire\Clients\Index;
use App\Models\Client;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('el botón "Planificar" de la fila abre el modal para ese cliente', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Bar Central']);

    Livewire::test(Index::class)
        ->call('openPlanDelivery', $client->id)
        ->assertDispatched('open-modal', 'client-plan')
        ->assertSet('planForm.client.id', $client->id);
});

it('planRoutes solo lista rutas del mismo tipo de servicio que el cliente', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(); // service_kind = reparto por defecto
    makeRoute('2026-09-10'); // ruta de reparto
    $tripRoute = Route::factory()->trip()->create(['valid_from' => '2020-01-01', 'valid_until' => null]);

    Livewire::test(Index::class)
        ->call('openPlanDelivery', $client->id)
        ->assertCount('planRoutes', 1);

    expect($tripRoute)->not->toBeNull(); // control: existe pero no debe listarse (otro service_kind)
});

it('genera una parada por cada día de la semana marcado dentro del rango', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Comunidad Vista']);
    $routeDay = makeRoute('2026-09-14');

    Livewire::test(Index::class)
        ->call('openPlanDelivery', $client->id)
        ->set('planForm.route_id', (string) $routeDay->route_id)
        ->set('planForm.starts_on', '2026-09-14') // lunes
        ->set('planForm.ends_on', '2026-09-27') // 2 semanas
        ->call('togglePlanWeekday', 1) // lunes
        ->call('togglePlanWeekday', 5) // viernes
        ->call('savePlan')
        ->assertHasNoErrors();

    $dates = RouteStop::where('customer_name', 'Comunidad Vista')->pluck('scheduled_for')->map->toDateString()->sort()->values();
    expect($dates->all())->toBe(['2026-09-14', '2026-09-18', '2026-09-21', '2026-09-25']);
});

it('no duplica una parada si el cliente ya tiene una para ese día', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Ya Planificado', 'tax_id' => 'X-1']);
    $routeDay = makeRoute('2026-09-14');
    RouteStop::factory()->for($routeDay, 'route')->create(['customer_tax_id' => 'X-1', 'scheduled_for' => '2026-09-14']);

    Livewire::test(Index::class)
        ->call('openPlanDelivery', $client->id)
        ->set('planForm.starts_on', '2026-09-14')
        ->set('planForm.ends_on', '2026-09-14')
        ->call('togglePlanWeekday', 1)
        ->call('savePlan');

    expect(RouteStop::where('customer_tax_id', 'X-1')->count())->toBe(1);
});

it('exige al menos un día de la semana marcado', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create();

    Livewire::test(Index::class)
        ->call('openPlanDelivery', $client->id)
        ->set('planForm.starts_on', '2026-09-14')
        ->set('planForm.ends_on', '2026-09-20')
        ->call('savePlan')
        ->assertHasErrors(['planForm.weekdays']);
});

it('la fecha de fin no puede ser anterior a la de inicio', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create();

    Livewire::test(Index::class)
        ->call('openPlanDelivery', $client->id)
        ->set('planForm.starts_on', '2026-09-20')
        ->set('planForm.ends_on', '2026-09-14')
        ->call('togglePlanWeekday', 1)
        ->call('savePlan')
        ->assertHasErrors(['planForm.ends_on']);
});

it('rechaza un rango de más de un año', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create();

    Livewire::test(Index::class)
        ->call('openPlanDelivery', $client->id)
        ->set('planForm.starts_on', '2026-09-14')
        ->set('planForm.ends_on', Carbon::parse('2026-09-14')->addDays(400)->toDateString())
        ->call('togglePlanWeekday', 1)
        ->call('savePlan')
        ->assertStatus(422);
});

it('un chofer no puede acceder al listado de clientes (donde vive "Planificar")', function () {
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);

    $this->actingAs($chofer)->get('/clientes')->assertForbidden();
});
