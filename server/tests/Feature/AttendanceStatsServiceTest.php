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

it('contractedWeeklySeconds usa la config por defecto si la persona no tiene horas propias', function () {
    config(['servalillo.attendance.default_weekly_hours' => 40]);
    $user = makeUser('chofer');

    expect($this->service->contractedWeeklySeconds($user->id))->toBe(40 * 3600);
});

it('contractedWeeklySeconds usa las horas propias de la persona si las tiene', function () {
    $user = makeUser('chofer');
    $user->update(['weekly_contracted_hours' => 20]);

    expect($this->service->contractedWeeklySeconds($user->id))->toBe(20 * 3600);
});

it('dailyBreakdownWithHours reparte ordinarias/extra dentro de la misma semana', function () {
    $user = makeUser('chofer');
    $user->update(['weekly_contracted_hours' => 10]); // umbral bajo para forzar el cruce a mitad de semana
    // Lunes 2026-09-14: 6h (todas ordinarias, quedan 4h de margen). Martes: 8h (4h ordinarias + 4h extra).
    Attendance::factory()->for($user)->create(['date' => '2026-09-14', 'out_at' => now(), 'total_seconds' => 6 * 3600]);
    Attendance::factory()->for($user)->create(['date' => '2026-09-15', 'out_at' => now(), 'total_seconds' => 8 * 3600]);

    $rows = collect($this->service->dailyBreakdownWithHours($user->id, '2026-09-14', '2026-09-15'))->keyBy('date');

    expect($rows['2026-09-14']['ordinary_seconds'])->toBe(6 * 3600);
    expect($rows['2026-09-14']['extra_seconds'])->toBe(0);
    expect($rows['2026-09-15']['ordinary_seconds'])->toBe(4 * 3600);
    expect($rows['2026-09-15']['extra_seconds'])->toBe(4 * 3600);
});

it('dailyBreakdownWithHours calcula el acumulado semanal aunque el rango pedido empiece a mitad de semana', function () {
    $user = makeUser('chofer');
    $user->update(['weekly_contracted_hours' => 10]);
    // Misma semana ISO (lunes 2026-09-14 a domingo 2026-09-20). Se pide solo el martes,
    // pero el lunes (fuera del rango pedido) ya ha consumido las 10h ordinarias.
    Attendance::factory()->for($user)->create(['date' => '2026-09-14', 'out_at' => now(), 'total_seconds' => 10 * 3600]);
    Attendance::factory()->for($user)->create(['date' => '2026-09-15', 'out_at' => now(), 'total_seconds' => 3 * 3600]);

    $rows = collect($this->service->dailyBreakdownWithHours($user->id, '2026-09-15', '2026-09-15'))->keyBy('date');

    expect($rows)->toHaveCount(1);
    expect($rows['2026-09-15']['ordinary_seconds'])->toBe(0);
    expect($rows['2026-09-15']['extra_seconds'])->toBe(3 * 3600);
});

it('dailyBreakdownWithHours no cuenta un día con jornada abierta como ordinaria ni extra', function () {
    $user = makeUser('chofer');
    $user->update(['weekly_contracted_hours' => 40]);
    Attendance::factory()->for($user)->create(['date' => '2026-09-14', 'in_at' => now(), 'out_at' => null, 'total_seconds' => null]);

    $rows = collect($this->service->dailyBreakdownWithHours($user->id, '2026-09-14', '2026-09-14'))->keyBy('date');

    expect($rows['2026-09-14']['open'])->toBeTrue();
    expect($rows['2026-09-14']['ordinary_seconds'])->toBeNull();
    expect($rows['2026-09-14']['extra_seconds'])->toBeNull();
});
