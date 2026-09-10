<?php

use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
