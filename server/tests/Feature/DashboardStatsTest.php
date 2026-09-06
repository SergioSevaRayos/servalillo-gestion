<?php

use App\Enums\RouteStopStatus;
use App\Livewire\Dashboard\Index;
use App\Models\Driver;
use App\Models\RouteStop;
use Livewire\Livewire;

it('muestra el panel estadístico al administrador', function () {
    $this->actingAs(makeUser('administrador'))
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Panel estadístico')
        ->assertSee('Tasa de éxito');
});

it('deja entrar a mantenimiento (superusuario técnico)', function () {
    $this->actingAs(makeUser('mantenimiento'))->get('/dashboard')->assertOk();
});

it('no deja el panel a un chofer', function () {
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);

    $this->actingAs($chofer)->get('/dashboard')->assertForbidden();
});

it('el componente aborta 403 si el usuario no tiene stats.view', function () {
    $user = makeUser('chofer');

    $this->actingAs($user);
    Livewire::test(Index::class)->assertForbidden();
});

it('calcula los KPIs del rango elegido', function () {
    $route = makeRoute(today()->subDays(3)->toDateString());
    RouteStop::factory()->for($route)->count(4)->create(['status' => RouteStopStatus::Completed, 'planned_quantity' => 1000, 'delivered_quantity' => 1000]);
    RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Failed, 'planned_quantity' => 200]);

    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->assertSet('range', '30d')
        ->assertViewHas('stats', fn (array $stats) => $stats['kpis']['stops_completed'] === 4
            && $stats['kpis']['stops_failed'] === 1
            && $stats['kpis']['liters_delivered'] === 4000.0);
});

it('cambia el rango y reemite los datos de los gráficos', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::test(Index::class)
        ->call('setRange', '7d')
        ->assertSet('range', '7d')
        ->assertDispatched('stats-updated');
});

it('ignora un rango inválido en la URL', function () {
    $this->actingAs(makeUser('administrador'));

    Livewire::withUrlParams(['range' => 'basura'])
        ->test(Index::class)
        ->assertSet('range', '30d');
});
