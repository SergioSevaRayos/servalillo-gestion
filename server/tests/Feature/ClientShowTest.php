<?php

use App\Enums\RouteStopStatus;
use App\Livewire\Clients\Show;
use App\Models\Client;
use App\Models\Driver;
use App\Models\RouteDay;
use App\Models\RouteStop;
use Livewire\Livewire;

it('muestra la ficha del cliente con su histórico emparejado por CIF', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Comunidad Teide', 'tax_id' => 'H99887766']);

    $route = RouteDay::factory()->create(['route_date' => today()->subDays(10)]);
    RouteStop::factory()->for($route, 'route')->create([
        'customer_name' => 'Otro nombre distinto', // no coincide, pero el CIF sí
        'customer_tax_id' => 'H99887766',
        'status' => RouteStopStatus::Completed,
        'delivered_quantity' => 1200,
    ]);
    RouteStop::factory()->for(RouteDay::factory(), 'route')->create(['customer_tax_id' => 'OTRO-CIF']);

    Livewire::test(Show::class, ['client' => $client])
        ->assertSee('Comunidad Teide')
        ->assertViewHas('deliveryTypes')
        ->assertSet('client.id', $client->id)
        ->assertSeeHtml('1.200')
        ->assertDontSeeHtml('OTRO-CIF');
});

it('planificar reparto abre el modal con las rutas candidatas de su tipo de servicio', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Taller Pérez', 'typical_quantity' => 500, 'latitude' => 28.46, 'longitude' => -16.25]);
    makeRoute('2026-09-10'); // ruta permanente de reparto (mismo service_kind por defecto)

    Livewire::test(Show::class, ['client' => $client])
        ->call('openPlanDelivery')
        ->assertDispatched('open-modal', 'client-plan')
        ->assertSet('planForm.client.id', $client->id)
        ->assertCount('planRoutes', 1);
});

it('planificar reparto con una ruta elegida crea las paradas del rango en esa ruta', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Taller Pérez', 'typical_quantity' => 500, 'latitude' => 28.46, 'longitude' => -16.25]);
    $routeDay = makeRoute('2026-09-14'); // lunes

    Livewire::test(Show::class, ['client' => $client])
        ->call('openPlanDelivery')
        ->set('planForm.route_id', (string) $routeDay->route_id)
        ->set('planForm.starts_on', '2026-09-14')
        ->set('planForm.ends_on', '2026-09-20')
        ->call('togglePlanWeekday', 1) // lunes
        ->call('togglePlanWeekday', 3) // miércoles
        ->call('savePlan')
        ->assertDispatched('toast');

    $stops = RouteStop::where('customer_name', 'Taller Pérez')->orderBy('scheduled_for')->with('route')->get();
    expect($stops)->toHaveCount(2)
        ->and($stops[0]->scheduled_for->toDateString())->toBe('2026-09-14')
        ->and($stops[1]->scheduled_for->toDateString())->toBe('2026-09-16')
        // cada día tiene su propio RouteDay, pero ambos cuelgan de la misma ruta permanente
        ->and($stops->pluck('route.route_id')->unique()->count())->toBe(1)
        ->and((float) $stops[0]->planned_quantity)->toBe(500.0)
        ->and($stops[0]->status)->toBe(RouteStopStatus::Pending);
});

it('planificar reparto sin ruta elegida deja las paradas en "Sin asignar"', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Sin Ruta SL']);

    Livewire::test(Show::class, ['client' => $client])
        ->call('openPlanDelivery')
        ->set('planForm.starts_on', '2026-09-14')
        ->set('planForm.ends_on', '2026-09-14')
        ->call('togglePlanWeekday', 1)
        ->call('savePlan');

    $stop = RouteStop::whereNull('route_id')->firstWhere('customer_name', 'Sin Ruta SL');
    expect($stop)->not->toBeNull()
        ->and($stop->scheduled_for->toDateString())->toBe('2026-09-14');
});

it('un chofer no ve la ficha de cliente', function () {
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);
    $client = Client::factory()->create();

    $this->actingAs($chofer)->get(route('clients.show', $client))->assertForbidden();
});
