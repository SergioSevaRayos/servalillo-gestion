<?php

use App\Livewire\Notifications\Bell;
use App\Livewire\Routes\Board;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\Route;
use App\Models\RouteStop;
use App\Notifications\StopManagedByOffice;
use App\Services\RecurringStopService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

it('oficina añade una parada a la ruta de un chofer y se lo notifica', function () {
    $route = makeRoute('2026-09-10');
    $chofer = $route->driver->user;

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('openCreateStop', $route->id)
        ->set('form.customer_name', 'Bar Nuevo')
        ->call('saveStop')
        ->assertHasNoErrors();

    Notification::assertSentTo($chofer, StopManagedByOffice::class,
        fn ($n) => $n->kind === 'added' && $n->customerName === 'Bar Nuevo');
});

it('oficina edita una parada y notifica "modificada"', function () {
    $route = makeRoute('2026-09-10');
    $chofer = $route->driver->user;
    $stop = RouteStop::factory()->for($route, 'route')->create(['customer_name' => 'Taller Gómez', 'planned_quantity' => 500]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('openEditStop', $stop)
        ->set('form.planned_quantity', 1200)
        ->call('saveStop')
        ->assertHasNoErrors();

    Notification::assertSentTo($chofer, StopManagedByOffice::class, fn ($n) => $n->kind === 'modified');
});

it('oficina elimina una parada y notifica "quitada"', function () {
    $route = makeRoute('2026-09-10');
    $chofer = $route->driver->user;
    $stop = RouteStop::factory()->for($route, 'route')->create(['customer_name' => 'Finca Sur']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('deleteStop', $stop);

    Notification::assertSentTo($chofer, StopManagedByOffice::class,
        fn ($n) => $n->kind === 'removed' && $n->customerName === 'Finca Sur');
});

it('oficina arrastra una parada de "Sin asignar" a la ruta y notifica', function () {
    $route = makeRoute('2026-09-10');
    $chofer = $route->driver->user;
    $stop = RouteStop::factory()->create(['route_id' => null, 'position' => 1, 'customer_name' => 'Cliente Suelto']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', null, [], $route->id, [$stop->id]);

    Notification::assertSentTo($chofer, StopManagedByOffice::class, fn ($n) => $n->kind === 'added');
});

it('oficina saca una parada de la ruta y notifica "quitada"', function () {
    $route = makeRoute('2026-09-10');
    $chofer = $route->driver->user;
    $stop = RouteStop::factory()->for($route, 'route')->create(['position' => 1, 'customer_name' => 'Se Va']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', $route->id, [], null, [$stop->id]);

    Notification::assertSentTo($chofer, StopManagedByOffice::class,
        fn ($n) => $n->kind === 'removed' && $n->customerName === 'Se Va');
});

it('reordenar dentro de la misma ruta NO notifica (solo cambia la posición)', function () {
    $route = makeRoute('2026-09-10');
    $chofer = $route->driver->user;
    $a = RouteStop::factory()->for($route, 'route')->create(['position' => 1]);
    $b = RouteStop::factory()->for($route, 'route')->create(['position' => 2]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', $route->id, [$b->id, $a->id], $route->id, [$b->id, $a->id]);

    Notification::assertNotSentTo($chofer, StopManagedByOffice::class);
});

it('un cambio hecho por el propio chofer no dispara el aviso de oficina', function () {
    $route = makeRoute('2026-09-10');
    $chofer = $route->driver->user;
    $stop = RouteStop::factory()->for($route, 'route')->create();

    $this->actingAs($chofer);
    $stop->update(['planned_quantity' => 999]);

    Notification::assertNotSentTo($chofer, StopManagedByOffice::class);
});

it('la generación automática de paradas recurrentes no dispara el aviso', function () {
    DeliveryType::factory()->create(['name' => 'Suministro de agua', 'slug' => 'agua']);
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00')); // lunes

    $route = Route::factory()->create(['service_kind' => 'reparto', 'valid_from' => '2026-01-01', 'valid_until' => null]);
    $chofer = $route->driver->user;
    Client::factory()->weekly([1])->create(['name' => 'Recurrente Lunes']);

    Livewire::actingAs(makeUser('administrador'));
    app(RecurringStopService::class)->generateForDate(Carbon::parse('2026-03-02'));

    expect(RouteStop::where('customer_name', 'Recurrente Lunes')->first()->route_id)->not->toBeNull();
    Notification::assertNotSentTo($chofer, StopManagedByOffice::class);

    Carbon::setTestNow();
});

it('el chofer también tiene campana de notificaciones', function () {
    $chofer = makeUser('chofer');

    Livewire::actingAs($chofer)->test(Bell::class)->assertOk();

    $this->actingAs($chofer)->get('/chofer/ruta')->assertOk()->assertSeeLivewire(Bell::class);
});
