<?php

use App\Models\Attendance;
use App\Services\AttendanceStatsService;
use App\Support\Duration;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->service = app(AttendanceStatsService::class);
});

it('rangeFor calcula la semana ISO (lunes-domingo), no el default de locale', function () {
    // 2026-09-13 es domingo; 2026-09-14 es lunes de la semana siguiente.
    $sunday = Carbon::parse('2026-09-13');
    $monday = Carbon::parse('2026-09-14');

    $sundayRange = $this->service->rangeFor('week', $sunday);
    $mondayRange = $this->service->rangeFor('week', $monday);

    expect($sundayRange['from']->toDateString())->toBe('2026-09-07');
    expect($sundayRange['to']->toDateString())->toBe('2026-09-13');
    expect($mondayRange['from']->toDateString())->toBe('2026-09-14');
    expect($mondayRange['to']->toDateString())->toBe('2026-09-20');
});

it('totalsFor suma exactamente los segundos de los fichajes cerrados, sin redondear', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 3661]);
    Attendance::factory()->for($user)->create(['date' => '2026-09-11', 'out_at' => now(), 'total_seconds' => 5423]);
    // Fuera del rango: no debe sumar.
    Attendance::factory()->for($user)->create(['date' => '2026-09-01', 'out_at' => now(), 'total_seconds' => 9999]);

    $totals = $this->service->totalsFor($user->id, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'));

    expect($totals['seconds'])->toBe(3661 + 5423);
    expect($totals['days'])->toBe(2);
});

it('los días trabajados son un recuento de jornadas cerradas, no un equivalente de 8h', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 2 * 3600]); // 2h
    Attendance::factory()->for($user)->create(['date' => '2026-09-11', 'out_at' => now(), 'total_seconds' => 14 * 3600]); // 14h

    $totals = $this->service->totalsFor($user->id, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'));

    expect($totals['days'])->toBe(2);
    expect($totals['seconds'])->toBe(16 * 3600);
});

it('una jornada abierta no cuenta en totalsFor, solo en openShiftSeconds', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->create(['date' => today()->toDateString(), 'in_at' => now()->subHours(2), 'out_at' => null, 'total_seconds' => null]);

    $totals = $this->service->totalsFor($user->id, today()->startOfDay(), today()->endOfDay());
    expect($totals['seconds'])->toBe(0);
    expect($totals['days'])->toBe(0);

    $open = $this->service->openShiftSeconds($user->id);
    expect($open)->toBeGreaterThanOrEqual(2 * 3600 - 5);
    expect($open)->toBeLessThanOrEqual(2 * 3600 + 5);
});

it('openShiftSeconds es null si no hay jornada abierta hoy', function () {
    $user = makeUser('chofer');

    expect($this->service->openShiftSeconds($user->id))->toBeNull();
});

it('comparisonTable rellena a cero a quien no fichó y excluye a mantenimiento', function () {
    $withHours = makeUser('chofer');
    Attendance::factory()->for($withHours)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 3600]);
    $withoutHours = makeUser('administrador');
    $maintenance = makeUser('mantenimiento');

    $rows = collect($this->service->comparisonTable('2026-09-10', '2026-09-10'))->keyBy('user_id');

    expect($rows->has($withHours->id))->toBeTrue();
    expect($rows[$withHours->id]['seconds'])->toBe(3600);
    expect($rows[$withHours->id]['days'])->toBe(1);

    expect($rows->has($withoutHours->id))->toBeTrue();
    expect($rows[$withoutHours->id]['seconds'])->toBe(0);
    expect($rows[$withoutHours->id]['days'])->toBe(0);

    expect($rows->has($maintenance->id))->toBeFalse();
});

it('comparisonTable excluye a quien ficha con huella externa en la base', function () {
    $external = makeUser('chofer');
    $external->update(['attendance_mode' => 'external']);
    Attendance::factory()->for($external)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 3600]);

    $rows = collect($this->service->comparisonTable('2026-09-10', '2026-09-10'))->keyBy('user_id');

    expect($rows->has($external->id))->toBeFalse();
});

it('dailyBreakdown incluye un día con jornada abierta, marcado y sin segundos', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->create(['date' => '2026-09-10', 'out_at' => now(), 'total_seconds' => 3600]);
    Attendance::factory()->for($user)->create(['date' => '2026-09-11', 'in_at' => now(), 'out_at' => null, 'total_seconds' => null]);

    $rows = $this->service->dailyBreakdown($user->id, '2026-09-10', '2026-09-11');

    expect($rows)->toHaveCount(2);
    expect($rows[0]['date'])->toBe('2026-09-10');
    expect($rows[0]['open'])->toBeFalse();
    expect($rows[0]['seconds'])->toBe(3600);
    expect($rows[1]['date'])->toBe('2026-09-11');
    expect($rows[1]['open'])->toBeTrue();
    expect($rows[1]['seconds'])->toBeNull();
});

it('Duration::decimalHours da coma decimal española con 2 decimales', function () {
    expect(Duration::decimalHours(620100))->toBe('172,25 h'); // 172.25 h
    expect(Duration::decimalHours(3600))->toBe('1,00 h');
    expect(Duration::decimalHours(0))->toBe('0,00 h');
});
