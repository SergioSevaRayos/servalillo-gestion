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
        ->set('form.quantity_input', 800)
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

it('varios clientes con el código externo en blanco no colisionan (2026-09-23)', function () {
    $this->actingAs(makeUser('administrador'));
    Client::factory()->create(['name' => 'Uno', 'external_ref' => null]);

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Dos')
        ->set('form.external_ref', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Client::where('name', 'Dos')->value('external_ref'))->toBeNull();
});

it('avisa al momento de un formato de coordenada inválido al salir del campo (2026-09-23)', function () {
    $this->actingAs(makeUser('administrador'));

    $test = Livewire::test(Index::class)
        ->call('create')
        ->set('form.latitude', '36,876880') // coma en vez de punto decimal
        ->assertHasErrors('form.latitude')
        ->set('form.longitude', '1e5') // notación científica (además se sale de rango, entre esa y "numeric" ganan al regex)
        ->assertHasErrors('form.longitude')
        ->set('form.latitude', '+28.4682') // "+" inicial: numeric y between lo aceptan, solo falla el formato
        ->assertHasErrors('form.latitude');

    // No basta con que el error exista en el error bag: tiene que pintarse de verdad bajo
    // el campo (bug real, ver components/ui/input.blade.php).
    expect($test->html())->toContain('La latitud no tiene un formato válido');

    $test->set('form.latitude', '28.4682')->assertHasNoErrors('form.latitude');
});

it('unas coordenadas con espacios sueltos no revientan al guardar (2026-09-23)', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Con Espacios')
        ->set('form.latitude', ' 28.4682 ')
        ->set('form.longitude', '-16.2546 ')
        ->call('save')
        ->assertHasNoErrors();

    $client = Client::firstWhere('name', 'Con Espacios');
    expect((float) $client->latitude)->toBe(28.4682)
        ->and((float) $client->longitude)->toBe(-16.2546);
});

it('un código externo con solo espacios se guarda como null', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('create')
        ->set('form.name', 'Espacios En Ref')
        ->set('form.external_ref', '   ')
        ->call('save')
        ->assertHasNoErrors();

    expect(Client::firstWhere('name', 'Espacios En Ref')->external_ref)->toBeNull();
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

it('busca por teléfono ignorando espacios y guiones', function () {
    $this->actingAs(makeUser('administrador'));
    Client::factory()->create(['name' => 'Con Fijo', 'phone' => '632 307 329', 'secondary_phone' => null]);
    Client::factory()->create(['name' => 'Con Móvil Secundario', 'phone' => '922 111 222', 'secondary_phone' => '600-45-67-89']);
    Client::factory()->create(['name' => 'Otro Cliente', 'phone' => '928 999 888']);

    Livewire::test(Index::class)
        ->set('search', '632307329')->assertSee('Con Fijo')->assertDontSee('Otro Cliente')
        ->set('search', '307 329')->assertSee('Con Fijo')
        ->set('search', '60045')->assertSee('Con Móvil Secundario')->assertDontSee('Con Fijo');
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
