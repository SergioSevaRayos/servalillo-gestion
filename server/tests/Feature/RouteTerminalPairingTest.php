<?php

use App\Models\Device;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\RouteTerminal;
use App\Models\Truck;
use App\Services\RecurringRouteService;
use App\Services\RouteTerminalPairingService;
use Illuminate\Support\Carbon;

/*
| Terminal vinculado a una ruta (chofer sustituto): cualquier chofer que inicie sesión desde un
| navegador con la cookie `route_terminal` pasa directamente a gestionar esa ruta ese día.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
});

afterEach(fn () => Carbon::setTestNow());

/** Un chofer "suelto", sin ruta permanente propia. */
function looseDriver(): Driver
{
    $user = makeUser('chofer');

    return Driver::factory()->create(['user_id' => $user->id]);
}

it('visitar el enlace de un terminal pone la cookie y solo sella paired_at la primera vez', function () {
    $routeDay = makeRoute('2026-09-10');
    $terminal = RouteTerminal::factory()->create(['route_id' => $routeDay->route_id]);

    $this->get(route('terminal.pair', $terminal->token))
        ->assertRedirect(route('login'))
        ->assertCookie('route_terminal', $terminal->token);

    $terminal->refresh();
    $firstPairedAt = $terminal->paired_at;
    expect($firstPairedAt)->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-09-10 09:05:00'));
    $this->get(route('terminal.pair', $terminal->token))->assertRedirect(route('login'));

    $terminal->refresh();
    expect($terminal->paired_at->eq($firstPairedAt))->toBeTrue()
        ->and($terminal->last_used_at->toDateTimeString())->toBe('2026-09-10 09:05:00');
});

it('un token inválido o revocado da 404', function () {
    $this->get(route('terminal.pair', 'no-existe'))->assertNotFound();

    $routeDay = makeRoute('2026-09-10');
    $terminal = RouteTerminal::factory()->revoked()->create(['route_id' => $routeDay->route_id]);

    $this->get(route('terminal.pair', $terminal->token))->assertNotFound();
});

it('un chofer distinto al titular, con la cookie puesta, pasa a gestionar la ruta ese día', function () {
    $routeDay = makeRoute('2026-09-10');
    $driverA = $routeDay->driver;
    $deviceA = Device::factory()->create(['driver_id' => $driverA->id]);
    $terminal = RouteTerminal::factory()->create(['route_id' => $routeDay->route_id]);

    $driverB = looseDriver();

    $this->actingAs($driverB->user)
        ->withCookie('route_terminal', $terminal->token)
        ->get(route('home'))
        ->assertRedirect(route('chofer.today'));

    expect($routeDay->fresh()->driver_id)->toBe($driverB->id)
        ->and($deviceA->fresh()->driver_id)->toBe($driverB->id);

    expect($terminal->fresh()->last_used_at)->not->toBeNull();
});

it('el chofer titular entrando desde el mismo terminal no cambia nada', function () {
    $routeDay = makeRoute('2026-09-10');
    $driverA = $routeDay->driver;
    $driverA->user->assignRole('chofer'); // makePermanentRoute() no asigna rol a su chofer
    $deviceA = Device::factory()->create(['driver_id' => $driverA->id]);
    $terminal = RouteTerminal::factory()->create(['route_id' => $routeDay->route_id]);

    $this->actingAs($driverA->user)
        ->withCookie('route_terminal', $terminal->token)
        ->get(route('home'))
        ->assertRedirect(route('chofer.today'));

    expect($routeDay->fresh()->driver_id)->toBe($driverA->id)
        ->and($deviceA->fresh()->driver_id)->toBe($driverA->id);
});

it('un token inválido en la cookie no sustituye nada (flujo normal)', function () {
    $routeDay = makeRoute('2026-09-10');
    $originalDriverId = $routeDay->driver_id;
    $driverB = looseDriver();

    $this->actingAs($driverB->user)
        ->withCookie('route_terminal', 'bogus-token')
        ->get(route('home'))
        ->assertRedirect(route('chofer.today'));

    expect($routeDay->fresh()->driver_id)->toBe($originalDriverId);
});

it('la sustitución no se filtra al día siguiente', function () {
    $routeDay = makeRoute('2026-09-10');
    $driverA = $routeDay->driver_id;
    $terminal = RouteTerminal::factory()->create(['route_id' => $routeDay->route_id]);
    $driverB = looseDriver();

    $this->actingAs($driverB->user)
        ->withCookie('route_terminal', $terminal->token)
        ->get(route('home'));

    expect($routeDay->fresh()->driver_id)->toBe($driverB->id);

    app(RecurringRouteService::class)->generateForDate(Carbon::parse('2026-09-11'));

    $tomorrow = RouteDay::where('route_id', $routeDay->route_id)
        ->whereDate('route_date', '2026-09-11')
        ->first();

    expect($tomorrow)->not->toBeNull()
        ->and($tomorrow->driver_id)->toBe($driverA);
});

it('un chofer sin perfil de Driver, o un usuario no chofer, no se ve afectado', function () {
    $routeDay = makeRoute('2026-09-10');
    $originalDriverId = $routeDay->driver_id;
    $terminal = RouteTerminal::factory()->create(['route_id' => $routeDay->route_id]);

    $userNoDriver = makeUser('chofer'); // rol chofer pero sin fila Driver

    $result = app(RouteTerminalPairingService::class)->resolveDriverHome($userNoDriver);
    expect($result)->toBe('chofer.today')
        ->and($routeDay->fresh()->driver_id)->toBe($originalDriverId);

    $admin = makeUser('administrador');
    $this->actingAs($admin)
        ->withCookie('route_terminal', $terminal->token)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));

    expect($routeDay->fresh()->driver_id)->toBe($originalDriverId);
});

it('si el sustituto ya tiene su propio dispositivo, no se le roba ni se toca el del titular', function () {
    $routeDay = makeRoute('2026-09-10');
    $driverA = $routeDay->driver;
    $deviceA = Device::factory()->create(['driver_id' => $driverA->id]);
    $terminal = RouteTerminal::factory()->create(['route_id' => $routeDay->route_id]);

    $driverB = looseDriver();
    $deviceB = Device::factory()->create(['driver_id' => $driverB->id]);

    $this->actingAs($driverB->user)
        ->withCookie('route_terminal', $terminal->token)
        ->get(route('home'));

    expect($routeDay->fresh()->driver_id)->toBe($driverB->id) // la ruta sí se sustituye
        ->and($deviceA->fresh()->driver_id)->toBe($driverA->id) // pero ningún dispositivo cambia
        ->and($deviceB->fresh()->driver_id)->toBe($driverB->id);
});

it('guarda de colisión: si el sustituto ya lleva su propia ruta hoy, no se le sustituye nada', function () {
    $routeDayA = makeRoute('2026-09-10'); // ruta de A, con terminal vinculado
    $originalDriverIdA = $routeDayA->driver_id;
    $terminal = RouteTerminal::factory()->create(['route_id' => $routeDayA->route_id]);

    $driverB = looseDriver();
    $truckB = Truck::factory()->create();
    $routeB = Route::factory()->create([
        'truck_id' => $truckB->id, 'driver_id' => $driverB->id,
        'valid_from' => '2020-01-01', 'valid_until' => null,
    ]);
    $routeDayB = RouteDay::factory()->create([
        'route_id' => $routeB->id, 'truck_id' => $truckB->id, 'driver_id' => $driverB->id,
        'route_date' => '2026-09-10',
    ]);

    $this->actingAs($driverB->user)
        ->withCookie('route_terminal', $terminal->token)
        ->get(route('home'))
        ->assertRedirect(route('chofer.today'));

    expect($routeDayA->fresh()->driver_id)->toBe($originalDriverIdA) // sin cambios
        ->and($routeDayB->fresh()->driver_id)->toBe($driverB->id); // sigue con la suya
});
