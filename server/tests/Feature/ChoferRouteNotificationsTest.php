<?php

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Chofer\Today;
use App\Livewire\Routes\Board;
use App\Models\Client;
use App\Notifications\ChoferRouteChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    Queue::fake();
    Notification::fake();

    $this->admins = collect([makeUser('administrador'), makeUser('administrador')]);
});

it('marcar una parada fallida notifica a los administradores', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);
    $route->stops->first()->update(['customer_name' => 'Bar Central']);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $route->stops->first()->id)
        ->set('form.outcome', 'failed')
        ->set('form.reason', 'Cliente ausente')
        ->call('saveStop')
        ->assertHasNoErrors();

    Notification::assertSentTo($this->admins->all(), ChoferRouteChanged::class,
        fn ($n) => $n->kind === 'stop_failed' && $n->customerName === 'Bar Central');
});

it('omitir una parada notifica', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $route->stops->first()->id)
        ->set('form.outcome', 'skipped')
        ->set('form.reason', 'Para mañana')
        ->call('saveStop');

    Notification::assertSentTo($this->admins->first(), ChoferRouteChanged::class,
        fn ($n) => $n->kind === 'stop_skipped');
});

it('reprogramar una parada notifica con la fecha destino y el cliente', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);
    $route->stops->first()->update(['customer_name' => 'Bar Central']);
    $target = today()->addDays(3)->toDateString();

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $route->stops->first()->id)
        ->set('form.outcome', 'failed')
        ->set('form.reason', 'Nadie')
        ->set('form.reschedule_on', $target)
        ->call('saveStop')
        ->assertHasNoErrors();

    Notification::assertSentTo($this->admins->first(), ChoferRouteChanged::class, function ($n) use ($target) {
        return $n->kind === 'stop_rescheduled'
            && $n->customerName === 'Bar Central'
            && $n->detail === Carbon::parse($target)->format('d/m/Y');
    });
});

it('añadir un cliente sobre la marcha notifica', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);
    $client = Client::factory()->create(['name' => 'Cafetería La Plaza', 'is_active' => true]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openAddStop')
        ->set('clientSearch', 'Plaza')
        ->call('addClientStop', $client->id);

    Notification::assertSentTo($this->admins->first(), ChoferRouteChanged::class,
        fn ($n) => $n->kind === 'client_added' && $n->customerName === 'Cafetería La Plaza');
});

it('cerrar la jornada con descuadre notifica; sin descuadre no', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);
    $route->stops->first()->update(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 1000, 'completed_at' => now()]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->set('meterEnd', 501010)   // 10 L de más
        ->set('meterNote', 'Derrame en la manguera')
        ->call('endDay')
        ->assertHasNoErrors();

    Notification::assertSentTo($this->admins->first(), ChoferRouteChanged::class,
        fn ($n) => $n->kind === 'meter_discrepancy');
});

it('completar una parada normal no notifica', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openStop', $route->stops->first()->id)
        ->set('form.outcome', 'completed')
        ->set('form.delivered_quantity', 900)
        ->set('form.channel', 'physical')
        ->call('saveStop')
        ->assertHasNoErrors();

    Notification::assertNothingSentTo($this->admins->first());
});

it('terminar la jornada sin descuadre no notifica', function () {
    [$user, $driver, $route] = chofer(['status' => RouteStatus::InProgress, 'started_at' => now()], stops: 1);

    Livewire::actingAs($user)->test(Today::class)
        ->call('openEndDay')
        ->call('endDay')
        ->assertHasNoErrors();

    Notification::assertNothingSentTo($this->admins->first());
});

it('las ediciones de oficina en el tablero no notifican', function () {
    $admin = makeUser('administrador');
    $route = makeRoute(today()->toDateString());

    Livewire::actingAs($admin)->test(Board::class)
        ->set('date', today()->toDateString())
        ->call('openCreateStop', $route->id)
        ->set('form.customer_name', 'Nueva parada oficina')
        ->set('form.address', 'Calle Falsa 123')
        ->call('saveStop');

    Notification::assertNothingSentTo($this->admins->first());
});
