<?php

use App\Enums\ClientType;
use App\Enums\ServiceKind;
use App\Livewire\Clients\Index;
use App\Models\Client;
use App\Models\Driver;
use Livewire\Livewire;

it('el listado de clientes es solo para gestión', function () {
    $this->actingAs(makeUser('administrador'))->get('/clientes')->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get('/clientes')->assertOk();

    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);
    $this->actingAs($chofer)->get('/clientes')->assertForbidden();
});

it('crea, edita y elimina un cliente', function () {
    $this->actingAs(makeUser('administrador'));

    $c = Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Panadería La Espiga')
        ->set('form.tax_id', 'B12345678')
        ->set('form.city', 'La Laguna')
        ->set('form.typical_quantity', 800)
        ->set('form.frequency_days', 15)
        ->call('save')
        ->assertHasNoErrors();

    $client = Client::firstWhere('name', 'Panadería La Espiga');
    expect($client)->not->toBeNull()
        ->and($client->frequencyLabel())->toBe('Quincenal');

    $c->call('edit', $client->id)
        ->set('form.city', 'Tegueste')
        ->call('save');
    expect($client->fresh()->city)->toBe('Tegueste');

    $c->call('delete', $client->id);
    expect(Client::find($client->id))->toBeNull();
});

it('valida el código externo único', function () {
    $this->actingAs(makeUser('administrador'));
    Client::factory()->create(['external_ref' => 'AX-001']);

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Otro')
        ->set('form.external_ref', 'AX-001')
        ->call('save')
        ->assertHasErrors('form.external_ref');
});

it('busca y filtra', function () {
    $this->actingAs(makeUser('administrador'));
    Client::factory()->create(['name' => 'Finca Los Almendros', 'client_type' => ClientType::Agricola, 'is_active' => true]);
    Client::factory()->create(['name' => 'Bar Central', 'client_type' => ClientType::Empresa, 'is_active' => false]);

    Livewire::test(Index::class)
        ->set('search', 'Almendros')->assertSee('Finca Los Almendros')->assertDontSee('Bar Central')
        ->set('search', '')
        ->set('type', ClientType::Agricola->value)->assertSee('Finca Los Almendros')->assertDontSee('Bar Central')
        ->set('type', 'all')
        ->set('status', 'inactive')->assertSee('Bar Central')->assertDontSee('Finca Los Almendros');
});

it('filtra los que les toca reparto', function () {
    $this->actingAs(makeUser('administrador'));
    Client::factory()->create(['name' => 'Toca ya', 'last_served_on' => now()->subDays(40), 'frequency_days' => 30]);
    Client::factory()->create(['name' => 'Reciente', 'last_served_on' => now()->subDays(3), 'frequency_days' => 30]);

    Livewire::test(Index::class)
        ->set('schedule', 'due')
        ->assertSee('Toca ya')
        ->assertDontSee('Reciente');
});

it('guarda el tipo de servicio y filtra por él', function () {
    $this->actingAs(makeUser('administrador'));
    Client::factory()->create(['name' => 'Cliente Reparto']);
    Client::factory()->trip()->create(['name' => 'Cliente Viaje']);

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Nuevo de Viaje')
        ->set('form.service_kind', 'viaje')
        ->call('save')
        ->assertHasNoErrors();

    expect(Client::firstWhere('name', 'Nuevo de Viaje')->service_kind)
        ->toBe(ServiceKind::Viaje);

    Livewire::test(Index::class)
        ->set('kind', 'viaje')
        ->assertSee('Cliente Viaje')
        ->assertDontSee('Cliente Reparto')
        ->set('kind', 'reparto')
        ->assertSee('Cliente Reparto')
        ->assertDontSee('Cliente Viaje');
});
