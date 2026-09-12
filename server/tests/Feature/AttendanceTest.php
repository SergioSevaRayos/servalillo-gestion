<?php

use App\Livewire\Attendance\Index;
use App\Models\Attendance;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('servalillo.attendance.enabled', true);
    config()->set('servalillo.base.latitude', 36.876880);
    config()->set('servalillo.base.longitude', -2.443087);
    config()->set('servalillo.attendance.default_radius_meters', 150);
});

it('devuelve 404 si el bloque está desactivado', function () {
    config()->set('servalillo.attendance.enabled', false);

    $this->actingAs(makeUser('administrador'))->get('/fichar')->assertNotFound();
});

it('deja fichar a administrador y a chofer', function () {
    $this->actingAs(makeUser('administrador'))->get('/fichar')->assertOk();
    $this->actingAs(makeUser('chofer'))->get('/fichar')->assertOk();
});

it('no deja fichar a mantenimiento', function () {
    $this->actingAs(makeUser('mantenimiento'))->get('/fichar')->assertForbidden();
});

it('ficha entrada y salida en la ubicación de la base', function () {
    $user = makeUser('administrador');

    Livewire::actingAs($user)->test(Index::class)
        ->call('punchIn', 36.876880, -2.443087)
        ->assertDispatched('toast');

    $attendance = Attendance::where('user_id', $user->id)->where('date', today()->toDateString())->first();
    expect($attendance)->not->toBeNull();
    expect($attendance->in_at)->not->toBeNull();
    expect($attendance->in_out_of_bounds)->toBeFalse();

    Livewire::actingAs($user)->test(Index::class)
        ->call('punchOut', 36.876880, -2.443087)
        ->assertDispatched('toast');

    $attendance->refresh();
    expect($attendance->out_at)->not->toBeNull();
    expect($attendance->total_seconds)->not->toBeNull();
});

it('permite fichar sin coordenadas, sin marcar fuera de zona', function () {
    $user = makeUser('administrador');

    Livewire::actingAs($user)->test(Index::class)->call('punchIn', null, null);

    $attendance = Attendance::where('user_id', $user->id)->where('date', today()->toDateString())->first();
    expect($attendance->in_at)->not->toBeNull();
    expect($attendance->in_latitude)->toBeNull();
    expect($attendance->in_out_of_bounds)->toBeFalse();
});

it('no deja fichar la entrada dos veces el mismo día', function () {
    $user = makeUser('administrador');
    Attendance::factory()->for($user)->create(['date' => today()->toDateString()]);

    Livewire::actingAs($user)->test(Index::class)
        ->call('punchIn', null, null)
        ->assertStatus(422);
});

it('no deja fichar la salida sin haber fichado la entrada', function () {
    $user = makeUser('administrador');

    Livewire::actingAs($user)->test(Index::class)
        ->call('punchOut', null, null)
        ->assertStatus(422);
});

it('marca fuera de zona un fichaje lejos de la base, sin bloquearlo', function () {
    $user = makeUser('chofer');

    Livewire::actingAs($user)->test(Index::class)
        ->call('punchIn', 40.4168, -3.7038); // Madrid, muy lejos de la base

    $attendance = Attendance::where('user_id', $user->id)->where('date', today()->toDateString())->first();
    expect($attendance->in_at)->not->toBeNull();
    expect($attendance->in_out_of_bounds)->toBeTrue();
});

it('usa la geovalla remota propia de un chofer en vez de la base', function () {
    $user = makeUser('chofer');
    $user->update([
        'attendance_mode' => 'remote',
        'attendance_latitude' => 40.4168,
        'attendance_longitude' => -3.7038,
        'attendance_radius_meters' => 100,
    ]);

    Livewire::actingAs($user)->test(Index::class)->call('punchIn', 40.4168, -3.7038);

    $attendance = Attendance::where('user_id', $user->id)->where('date', today()->toDateString())->first();
    expect($attendance->in_out_of_bounds)->toBeFalse();
});

it('solo ve su propio historial, no el de otros', function () {
    $user = makeUser('administrador');
    $other = makeUser('chofer');
    Attendance::factory()->for($other)->create(['date' => today()->subDay()->toDateString()]);
    Attendance::factory()->for($user)->closed()->create(['date' => today()->subDay()->toDateString()]);

    $history = Livewire::actingAs($user)->test(Index::class)->instance()->history();

    expect($history)->toHaveCount(1);
    expect($history->first()->user_id)->toBe($user->id);
});
