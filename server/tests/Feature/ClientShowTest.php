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

it('planificar reparto crea una parada en el backlog con los datos del cliente', function () {
    $this->actingAs(makeUser('administrador'));
    $client = Client::factory()->create(['name' => 'Taller Pérez', 'typical_quantity' => 500, 'latitude' => 28.46, 'longitude' => -16.25]);

    Livewire::test(Show::class, ['client' => $client])
        ->call('planDelivery')
        ->assertDispatched('toast');

    $stop = RouteStop::whereNull('route_id')->firstWhere('customer_name', 'Taller Pérez');
    expect($stop)->not->toBeNull()
        ->and((float) $stop->planned_quantity)->toBe(500.0)
        ->and($stop->status)->toBe(RouteStopStatus::Pending);
});

it('un chofer no ve la ficha de cliente', function () {
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);
    $client = Client::factory()->create();

    $this->actingAs($chofer)->get(route('clients.show', $client))->assertForbidden();
});
