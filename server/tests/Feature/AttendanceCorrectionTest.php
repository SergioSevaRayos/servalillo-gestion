<?php

use App\Livewire\Attendance\Manage;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('servalillo.attendance.enabled', true);
});

it('devuelve 404 si el bloque está desactivado', function () {
    config()->set('servalillo.attendance.enabled', false);

    $this->actingAs(makeUser('administrador'))->get('/fichajes/gestion')->assertNotFound();
});

it('deja entrar a administrador y a mantenimiento', function () {
    $this->actingAs(makeUser('administrador'))->get('/fichajes/gestion')->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get('/fichajes/gestion')->assertOk();
});

it('no deja entrar a un chofer', function () {
    $this->actingAs(makeUser('chofer'))->get('/fichajes/gestion')->assertForbidden();
    Livewire::actingAs(makeUser('chofer'))->test(Manage::class)->assertForbidden();
});

it('exige un motivo para corregir un fichaje', function () {
    $target = makeUser('chofer');
    $attendance = Attendance::factory()->for($target)->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->set('correctingId', $attendance->id)
        ->set('correct_in_at', $attendance->in_at->format('Y-m-d\TH:i'))
        ->set('correct_out_at', '')
        ->set('correct_reason', '')
        ->call('saveCorrect')
        ->assertHasErrors(['correct_reason']);

    expect(AttendanceCorrection::count())->toBe(0);
});

it('corrige un fichaje con motivo y deja rastro en el ledger', function () {
    $target = makeUser('chofer');
    $admin = makeUser('administrador');
    $attendance = Attendance::factory()->for($target)->create(['date' => today()->toDateString()]);

    Livewire::actingAs($admin)
        ->test(Manage::class)
        ->set('correctingId', $attendance->id)
        ->set('correct_in_at', today()->setTime(9, 0)->format('Y-m-d\TH:i'))
        ->set('correct_out_at', today()->setTime(17, 0)->format('Y-m-d\TH:i'))
        ->set('correct_reason', 'Olvidó fichar la salida, confirmado con el chofer.')
        ->call('saveCorrect')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal');

    $attendance->refresh();
    expect($attendance->out_at)->not->toBeNull();
    expect($attendance->total_seconds)->toBe(8 * 3600);

    $correction = AttendanceCorrection::where('attendance_id', $attendance->id)->first();
    expect($correction)->not->toBeNull();
    expect($correction->corrected_by)->toBe($admin->id);
    expect($correction->reason)->not->toBe('');
});

it('registra un fichaje olvidado (día sin ninguna fila) con motivo', function () {
    $target = makeUser('chofer');
    $admin = makeUser('mantenimiento');

    Livewire::actingAs($admin)
        ->test(Manage::class)
        ->set('create_user_id', $target->id)
        ->set('create_date', today()->subDay()->toDateString())
        ->set('create_in_at', today()->subDay()->setTime(8, 0)->format('Y-m-d\TH:i'))
        ->set('create_out_at', today()->subDay()->setTime(16, 0)->format('Y-m-d\TH:i'))
        ->set('create_reason', 'Olvidó fichar ambas horas, confirmado por teléfono.')
        ->call('saveCreate')
        ->assertHasNoErrors();

    $attendance = Attendance::where('user_id', $target->id)->where('date', today()->subDay()->toDateString())->first();
    expect($attendance)->not->toBeNull();
    expect($attendance->created_by)->toBe($admin->id);
    expect(AttendanceCorrection::where('attendance_id', $attendance->id)->count())->toBe(1);
});

it('exige un motivo para dar de alta un fichaje olvidado', function () {
    $target = makeUser('chofer');

    Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->set('create_user_id', $target->id)
        ->set('create_date', today()->toDateString())
        ->set('create_reason', '')
        ->call('saveCreate')
        ->assertHasErrors(['create_reason']);
});

it('cerrar ahora prefija la salida a la hora actual pero sigue exigiendo motivo', function () {
    $target = makeUser('chofer');
    $attendance = Attendance::factory()->for($target)->create(); // in_at 08:00, sin out_at

    $component = Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->call('openCloseNow', $attendance->id);

    expect($component->get('correct_out_at'))->not->toBe('');

    $component->set('correct_reason', '')
        ->call('saveCorrect')
        ->assertHasErrors(['correct_reason']);

    $component->set('correct_reason', 'Se fue sin fichar la salida, confirmado con el chofer.')
        ->call('saveCorrect')
        ->assertHasNoErrors();

    $attendance->refresh();
    expect($attendance->out_at)->not->toBeNull();
});

it('reabrir vacía la salida de un fichaje ya cerrado y exige motivo', function () {
    $target = makeUser('chofer');
    $attendance = Attendance::factory()->for($target)->closed()->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->call('openReopen', $attendance->id)
        ->assertSet('correct_out_at', '')
        ->set('correct_reason', 'Se cerró por error, el chofer siguió trabajando.')
        ->call('saveCorrect')
        ->assertHasNoErrors();

    $attendance->refresh();
    expect($attendance->out_at)->toBeNull();
    expect($attendance->total_seconds)->toBeNull();
});

it('ver ubicación manda los puntos de entrada/salida y la geovalla de la persona', function () {
    $target = makeUser('chofer');
    $attendance = Attendance::factory()->for($target)->closed()->create([
        'in_latitude' => 36.876880,
        'in_longitude' => -2.443087,
        'in_out_of_bounds' => false,
        'out_latitude' => 36.9,
        'out_longitude' => -2.5,
        'out_out_of_bounds' => true,
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->call('viewLocation', $attendance->id)
        ->assertDispatched('open-attendance-location', function (string $event, array $params) use ($target) {
            return $params['person'] === $target->name
                && $params['in']['lat'] === 36.876880
                && $params['out']['outOfBounds'] === true
                && isset($params['geofence']['lat'], $params['geofence']['radius']);
        });
});

it('ver ubicación no manda puntos cuando el fichaje no tiene coordenadas', function () {
    $target = makeUser('chofer');
    $attendance = Attendance::factory()->for($target)->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->call('viewLocation', $attendance->id)
        ->assertDispatched('open-attendance-location', fn (string $event, array $params) => $params['in'] === null && $params['out'] === null);
});
