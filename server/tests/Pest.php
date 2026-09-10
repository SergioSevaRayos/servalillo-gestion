<?php

use App\Models\Device;
use App\Models\Driver;
use App\Models\GpsPosition;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolePermissionSeeder::class))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function makeUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Ruta permanente (camión + chofer) ya vigente, sin ningún día generado todavía. La mayoría de
 * los tests quieren "el día concreto" con sus paradas — para eso usa makeRoute(), que además
 * crea el RouteDay de la fecha pedida.
 */
function makePermanentRoute(): Route
{
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    return Route::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => '2020-01-01',
        'valid_until' => null,
    ]);
}

/**
 * El día concreto (paradas, jornada, litros) de una ruta — lo que casi todos los tests
 * necesitan. Crea también la ruta permanente que lo respalda.
 */
function makeRoute(string $date = '2026-09-10'): RouteDay
{
    return makeRouteDay(makePermanentRoute(), $date);
}

/** El día de una ruta permanente ya existente (para tests que necesitan varios días de una misma ruta). */
function makeRouteDay(Route $route, string $date = '2026-09-10'): RouteDay
{
    return RouteDay::factory()->create([
        'route_id' => $route->id,
        'truck_id' => $route->truck_id,
        'driver_id' => $route->driver_id,
        'service_kind' => $route->service_kind->value,
        'route_date' => $date,
    ]);
}

/** Data URL de un PNG 1x1 válido (cabecera mágica real), para simular la firma del cliente. */
function fakeSignature(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

/**
 * Un punto GPS desplazado N metros al norte / M metros al este de (lat, lng).
 *
 * @return array{0: float, 1: float} [lat, lng]
 */
function metersOffset(float $lat, float $lng, float $north, float $east): array
{
    return [
        $lat + $north / 111_320,
        $lng + $east / (111_320 * cos(deg2rad($lat))),
    ];
}

/**
 * Inserta un track GPS para un día de ruta. Cada fix: [lat, lng, 'Y-m-d H:i:s', accuracy?, speed?].
 */
function gpsTrack(RouteDay $day, array $fixes): void
{
    $device = Device::factory()->create(['driver_id' => $day->driver_id]);

    GpsPosition::insert(array_map(fn (array $f) => [
        'device_id' => $device->id,
        'driver_id' => $day->driver_id,
        'truck_id' => $day->truck_id,
        'route_id' => $day->id,
        'latitude' => $f[0],
        'longitude' => $f[1],
        'recorded_at' => Carbon::parse($f[2]),
        'accuracy_m' => $f[3] ?? 10,
        'speed_mps' => $f[4] ?? null,
        'created_at' => now(),
    ], $fixes));
}
