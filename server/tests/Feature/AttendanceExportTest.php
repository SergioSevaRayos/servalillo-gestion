<?php

use App\Livewire\Attendance\Index;
use App\Livewire\Attendance\Manage;
use App\Models\Attendance;
use App\Services\AttendanceExportService;
use App\Services\AttendanceService;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('servalillo.attendance.enabled', true);
    config()->set('servalillo.company.name', 'Servalillo S.L.');
    config()->set('servalillo.company.tax_id', 'B12345678');
});

it('genera un XML de registro de jornada bien formado con los datos esperados', function () {
    $user = makeUser('chofer');
    $user->update(['dni' => '12345678A']);
    $attendance = Attendance::factory()->for($user)->closed()->create();

    $xml = app(AttendanceExportService::class)->toLegalXml(Attendance::where('id', $attendance->id)->get());

    $parsed = simplexml_load_string($xml);
    expect($parsed)->not->toBeFalse();
    expect((string) $parsed->Empresa->Nombre)->toBe('Servalillo S.L.');
    expect((string) $parsed->Empresa->CIF)->toBe('B12345678');
    expect((string) $parsed->Jornadas->Jornada->Empleado->Nombre)->toBe($user->name);
    expect((string) $parsed->Jornadas->Jornada->Empleado->DNI)->toBe('12345678A');
    expect((string) $parsed->Jornadas->Jornada->FueraDeZona)->toBe('no');
});

it('incluye las coordenadas del dispositivo en el XML', function () {
    $user = makeUser('chofer');
    $attendance = Attendance::factory()->for($user)->closed()->create([
        'in_latitude' => 36.876880,
        'in_longitude' => -2.443087,
        'out_latitude' => 36.9,
        'out_longitude' => -2.5,
    ]);

    $xml = app(AttendanceExportService::class)->toLegalXml(Attendance::where('id', $attendance->id)->get());
    $parsed = simplexml_load_string($xml);

    expect((string) $parsed->Jornadas->Jornada->EntradaLatitud)->toBe('36.8768800');
    expect((string) $parsed->Jornadas->Jornada->EntradaLongitud)->toBe('-2.4430870');
    expect((string) $parsed->Jornadas->Jornada->SalidaLatitud)->toBe('36.9000000');
});

it('deja vacías las coordenadas del XML si el fichaje no las tiene', function () {
    $user = makeUser('chofer');
    $attendance = Attendance::factory()->for($user)->create(['in_latitude' => null, 'in_longitude' => null]);

    $xml = app(AttendanceExportService::class)->toLegalXml(Attendance::where('id', $attendance->id)->get());
    $parsed = simplexml_load_string($xml);

    expect((string) $parsed->Jornadas->Jornada->EntradaLatitud)->toBe('');
});

it('marca fuera de zona en el XML si el fichaje quedó marcado', function () {
    $user = makeUser('administrador');
    $attendance = Attendance::factory()->for($user)->create(['in_out_of_bounds' => true]);

    $xml = app(AttendanceExportService::class)->toLegalXml(Attendance::where('id', $attendance->id)->get());
    $parsed = simplexml_load_string($xml);

    expect((string) $parsed->Jornadas->Jornada->FueraDeZona)->toBe('si');
});

it('el XML incluye el tiempo efectivo y el desglose ordinarias/extra en crudo y legible', function () {
    $user = makeUser('chofer');
    $user->update(['weekly_contracted_hours' => 40]);
    $attendance = Attendance::factory()->for($user)->closed()->create(); // 8h, muy por debajo del umbral semanal

    $xml = app(AttendanceExportService::class)->toLegalXml(Attendance::where('id', $attendance->id)->get());
    $parsed = simplexml_load_string($xml);
    $jornada = $parsed->Jornadas->Jornada;

    expect((string) $jornada->TiempoEfectivoSegundos)->toBe((string) (8 * 3600));
    expect((string) $jornada->TiempoOrdinario)->toBe('8 h');
    expect((string) $jornada->TiempoOrdinarioSegundos)->toBe((string) (8 * 3600));
    expect((string) $jornada->TiempoExtraordinario)->toBe('0 min');
    expect((string) $jornada->TiempoExtraordinarioSegundos)->toBe('0');
    expect((string) $jornada->Corregido)->toBe('no');
});

it('el XML marca Corregido y anota quién/cuándo/por qué de la última corrección', function () {
    $admin = makeUser('administrador');
    $user = makeUser('chofer');
    $attendance = Attendance::factory()->for($user)->closed()->create();

    app(AttendanceService::class)->correct($attendance, ['out_at' => $attendance->out_at->addHour()->toDateTimeString()], 'Olvidó fichar más tarde', $admin);

    $xml = app(AttendanceExportService::class)->toLegalXml(Attendance::where('id', $attendance->id)->get());
    $parsed = simplexml_load_string($xml);
    $jornada = $parsed->Jornadas->Jornada;

    expect((string) $jornada->Corregido)->toBe('si');
    expect((string) $jornada->UltimaCorreccion->Por)->toBe($admin->name);
    expect((string) $jornada->UltimaCorreccion->Motivo)->toBe('Olvidó fichar más tarde');
});

it('genera filas de PDF con las horas formateadas y si hubo corrección', function () {
    $user = makeUser('chofer');
    $attendance = Attendance::factory()->for($user)->closed()->create();

    $rows = app(AttendanceExportService::class)->toPdfRows(Attendance::where('id', $attendance->id)->get());

    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe($user->name);
    expect($rows[0]['hours'])->toBe('8 h');
    expect($rows[0]['ordinary_hours'])->toBe('8 h');
    expect($rows[0]['extra_hours'])->toBe('0 min');
    expect($rows[0]['corrected'])->toBeFalse();
    expect($rows[0]['corrected_note'])->toBeNull();
});

it('las filas del PDF incluyen el motivo de la última corrección cuando la hay', function () {
    $admin = makeUser('administrador');
    $user = makeUser('chofer');
    $attendance = Attendance::factory()->for($user)->closed()->create();

    app(AttendanceService::class)->correct($attendance, ['out_at' => $attendance->out_at->addHour()->toDateTimeString()], 'Salida corregida a mano', $admin);

    [$row] = app(AttendanceExportService::class)->toPdfRows(Attendance::where('id', $attendance->id)->get());

    expect($row['corrected'])->toBeTrue();
    expect($row['corrected_note'])->toBe('Salida corregida a mano');
});

it('toInteroperableArray devuelve el mismo contenido enriquecido en JSON', function () {
    $user = makeUser('chofer');
    $attendance = Attendance::factory()->for($user)->closed()->create([
        'in_latitude' => 36.876880, 'in_longitude' => -2.443087,
    ]);

    $data = app(AttendanceExportService::class)->toInteroperableArray(Attendance::where('id', $attendance->id)->get());

    expect($data['empresa']['nombre'])->toBe('Servalillo S.L.');
    expect($data['jornadas'])->toHaveCount(1);
    $jornada = $data['jornadas'][0];
    expect($jornada['empleado']['nombre'])->toBe($user->name);
    expect($jornada['entrada_latitud'])->toBe(36.87688);
    expect($jornada['tiempo_efectivo_segundos'])->toBe(8 * 3600);
    expect($jornada['tiempo_ordinario_segundos'])->toBe(8 * 3600);
    expect($jornada['corregido'])->toBeFalse();
    expect($jornada['ultima_correccion'])->toBeNull();
});

it('las filas del PDF incluyen las coordenadas del dispositivo, o null si no hay', function () {
    $user = makeUser('chofer');
    $withCoords = Attendance::factory()->for($user)->closed()->create([
        'in_latitude' => 36.876880, 'in_longitude' => -2.443087,
    ]);

    [$row] = app(AttendanceExportService::class)->toPdfRows(Attendance::where('id', $withCoords->id)->get());
    expect($row['in_coords'])->toBe('36.876880, -2.443087');

    $withoutCoords = Attendance::factory()->for($user)->create([
        'date' => today()->subDay()->toDateString(),
    ]);

    [$row] = app(AttendanceExportService::class)->toPdfRows(Attendance::where('id', $withoutCoords->id)->get());
    expect($row['in_coords'])->toBeNull();
    expect($row['out_coords'])->toBeNull();
});

it('cualquier usuario con fichaje propio puede exportar su historial en PDF sin permiso adicional', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->closed()->create();

    Livewire::actingAs($user)->test(Index::class)
        ->call('exportPdf')
        ->assertFileDownloaded('mis-horas.pdf');
});

it('cualquier usuario con fichaje propio puede exportar su historial en formato legal sin permiso adicional', function () {
    $user = makeUser('administrador');
    Attendance::factory()->for($user)->closed()->create();

    Livewire::actingAs($user)->test(Index::class)
        ->call('exportXml')
        ->assertFileDownloaded('mis-horas.xml');
});

it('el panel de gestión exporta XML y PDF del mes filtrado', function () {
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->closed()->create(['date' => today()->toDateString()]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->set('month', today()->format('Y-m'))
        ->call('exportXml')
        ->assertFileDownloaded('registro-jornada-'.today()->format('Y-m').'.xml');

    Livewire::actingAs(makeUser('administrador'))
        ->test(Manage::class)
        ->set('month', today()->format('Y-m'))
        ->call('exportPdf')
        ->assertFileDownloaded('registro-jornada-'.today()->format('Y-m').'.pdf');
});
