<?php

use App\Enums\ClientStatus;
use App\Enums\PriceType;
use App\Enums\ServiceKind;
use App\Livewire\Clients\Index;
use App\Livewire\Clients\Show;
use App\Models\Client;
use Livewire\Livewire;

it('crea un pre-cliente desde el modal con el toggle y ofrece el resumen para WhatsApp', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.status', 'prospect')
        ->set('form.name', 'Llamada de María')
        ->set('form.phone', '600 111 222')
        ->set('form.address', 'Camino del Pozo 3')
        ->set('form.water_type', 'corriente')
        ->set('form.quantity_input', 3)
        ->set('form.quantity_unit', 'm3')
        ->set('form.tank_distance_m', 25)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('open-prospect-summary', function ($event, $params) {
            return str_contains($params['text'], 'Llamada de María')
                && str_contains($params['text'], '600 111 222')
                && str_contains($params['text'], 'Camino del Pozo 3')
                && str_contains($params['text'], '25 m');
        });

    $c = Client::firstWhere('name', 'Llamada de María');
    expect($c->status)->toBe(ClientStatus::Prospect)
        ->and((float) $c->typical_quantity)->toBe(3000.0)
        ->and($c->quantity_unit)->toBe('m3')
        ->and($c->tank_distance_m)->toBe(25);
});

it('un pre-cliente puede ser de tipo viaje', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.status', 'prospect')
        ->set('form.name', 'Viaje puntual')
        ->set('form.service_kind', 'viaje')
        ->call('save')
        ->assertHasNoErrors();

    expect(Client::firstWhere('name', 'Viaje puntual')->service_kind)->toBe(ServiceKind::Viaje);
});

it('crear un cliente normal (no pre-cliente) no abre el resumen de WhatsApp', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Cliente Directo')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotDispatched('open-prospect-summary');
});

it('guarda el precio como tarifa fija o por litro', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Cliente Tarifa')
        ->set('form.price', 45)
        ->set('form.price_type', 'fixed')
        ->call('save')
        ->assertHasNoErrors();

    $c = Client::firstWhere('name', 'Cliente Tarifa');
    expect($c->price_type)->toBe(PriceType::Fixed)
        ->and((float) $c->price)->toBe(45.0)
        ->and($c->priceLabel())->toContain('45,00 €')
        ->and($c->priceLabel())->toContain('Tarifa fija');
});

it('el listado oculta los pre-clientes salvo con el filtro', function () {
    $this->actingAs(makeUser('administrador'));
    Client::factory()->create(['name' => 'Cliente Real']);
    Client::factory()->prospect()->create(['name' => 'Prospecto Pepe']);

    Livewire::test(Index::class)
        ->assertSee('Cliente Real')->assertDontSee('Prospecto Pepe')
        ->set('status', 'prospect')
        ->assertSee('Prospecto Pepe')->assertDontSee('Cliente Real');
});

it('aprobar convierte el pre-cliente en cliente y abre el modal de edición', function () {
    $this->actingAs(makeUser('administrador'));
    $p = Client::factory()->prospect()->create(['name' => 'Prospecto Ana']);

    Livewire::test(Index::class)
        ->call('approve', $p->id)
        ->assertDispatched('open-modal')
        ->assertSet('form.status', 'customer')
        ->assertSet('form.name', 'Prospecto Ana');

    expect($p->fresh()->status)->toBe(ClientStatus::Customer);
});

it('descartar borra el pre-cliente definitivamente', function () {
    $this->actingAs(makeUser('administrador'));
    $p = Client::factory()->prospect()->create();

    Livewire::test(Index::class)->call('discard', $p->id);

    expect(Client::withTrashed()->find($p->id))->toBeNull();
});

it('no se puede aprobar ni descartar un cliente que ya es real', function () {
    $this->actingAs(makeUser('administrador'));
    $c = Client::factory()->create();

    Livewire::test(Index::class)->call('approve', $c->id)->assertStatus(404);
    Livewire::test(Index::class)->call('discard', $c->id)->assertStatus(404);
});

it('no se puede planificar reparto de un pre-cliente', function () {
    $this->actingAs(makeUser('administrador'));
    $p = Client::factory()->prospect()->create();

    Livewire::test(Show::class, ['client' => $p])
        ->call('planDelivery')
        ->assertStatus(403);
});

it('al editar un pre-cliente en m³ el número vuelve a su unidad', function () {
    $this->actingAs(makeUser('administrador'));
    $p = Client::factory()->prospect()->create(['typical_quantity' => 2000, 'quantity_unit' => 'm3']);

    Livewire::test(Show::class, ['client' => $p])
        ->call('edit')
        ->assertSet('form.quantity_input', 2.0)
        ->assertSet('form.quantity_unit', 'm3');
});

it('la ficha de un pre-cliente no muestra histórico ni planificar', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Show::class, ['client' => Client::factory()->prospect()->create()])
        ->assertSee('Pendiente valoración')
        ->assertDontSee('Histórico de repartos')
        ->assertDontSee('Planificar');
});
