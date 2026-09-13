<?php

use App\Livewire\Attendance\Totals;
use App\Models\Attendance;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('servalillo.attendance.enabled', true);
});

it('devuelve 404 si el bloque está desactivado', function () {
    config()->set('servalillo.attendance.enabled', false);

    $this->actingAs(makeUser('administrador'))->get('/fichajes/totales')->assertNotFound();
});

it('deja entrar a administrador y a mantenimiento', function () {
    $this->actingAs(makeUser('administrador'))->get('/fichajes/totales')->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get('/fichajes/totales')->assertOk();
});

it('no deja entrar a un chofer', function () {
    $this->actingAs(makeUser('chofer'))->get('/fichajes/totales')->assertForbidden();
    Livewire::actingAs(makeUser('chofer'))->test(Totals::class)->assertForbidden();
});

it('cambiar el periodo o la fecha ancla recalcula las filas', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 3600]);
    Attendance::factory()->for($user)->create(['date' => '2026-08-10', 'out_at' => now(), 'total_seconds' => 7200]);

    $component = Livewire::actingAs(makeUser('administrador'))
        ->test(Totals::class)
        ->set('period', 'month')
        ->set('anchor', '2026-09-15');

    $rows = collect($component->get('rows'))->keyBy('user_id');
    expect($rows[$user->id]['seconds'])->toBe(3600);

    $component->set('anchor', '2026-08-15');
    $rows = collect($component->get('rows'))->keyBy('user_id');
    expect($rows[$user->id]['seconds'])->toBe(7200);
});

it('ver detalle abre el modal con el desglose día a día de esa persona', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 3600]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Totals::class)
        ->set('period', 'month')
        ->set('anchor', '2026-09-15')
        ->call('viewDetail', $user->id)
        ->assertDispatched('open-modal', 'attendance-totals-detail')
        ->assertSet('detailUserId', $user->id);
});

it('excluye de la comparativa a quien ficha con huella externa en la base', function () {
    $external = makeUser('chofer');
    $external->update(['attendance_mode' => 'external']);
    Attendance::factory()->for($external)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 3600]);

    $rows = Livewire::actingAs(makeUser('administrador'))
        ->test(Totals::class)
        ->set('period', 'month')
        ->set('anchor', '2026-09-15')
        ->get('rows');

    expect(collect($rows)->pluck('user_id'))->not->toContain($external->id);
});
