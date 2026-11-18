<?php

use App\Enums\AlertType;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Alerts\Index;
use App\Livewire\Fleet\Map;
use App\Livewire\Layout\NavBadge;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\GpsPosition;
use App\Models\RouteStop;
use App\Models\Truck;
use App\Notifications\FleetAlert;
use App\Services\AlertEvaluator;
use App\Support\TrackingWindow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->admin = makeUser('administrador');
    // Miércoles 10:00 — dentro de la ventana de tracking (pausa 22:00–05:00).
    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(10, 0));
});

/** Ruta de hoy + dispositivo de su chofer, con la última señal hace $minutes. */
function onRoute(?int $minutesSilent = 1, RouteStatus $status = RouteStatus::InProgress): array
{
    $route = makeRoute(today()->toDateString());
    $route->update(['status' => $status]);
    $device = Device::factory()->create([
        'driver_id' => $route->driver_id,
        'last_seen_at' => $minutesSilent === null ? null : now()->subMinutes($minutesSilent),
    ]);

    return [$route, $device];
}

function position(Device $device, array $attrs = []): GpsPosition
{
    return GpsPosition::factory()->create([
        'device_id' => $device->id,
        'truck_id' => Truck::factory()->create()->id,
        'recorded_at' => now()->subMinute(),
        'speed_mps' => 5,
        'battery_level' => 80,
        ...$attrs,
    ]);
}

function evaluate(): array
{
    return app(AlertEvaluator::class)->run();
}

it('la ventana de tracking pausa de 22:00 a 05:00', function () {
    expect(TrackingWindow::isActive(now()->setTime(10, 0)))->toBeTrue()
        ->and(TrackingWindow::isActive(now()->setTime(4, 59)))->toBeFalse()
        ->and(TrackingWindow::isActive(now()->setTime(5, 0)))->toBeTrue()
        ->and(TrackingWindow::isActive(now()->setTime(21, 59)))->toBeTrue()
        ->and(TrackingWindow::isActive(now()->setTime(22, 0)))->toBeFalse()
        ->and(TrackingWindow::isActive(now()->setTime(23, 30)))->toBeFalse();
});

it('avisa una sola vez cuando un dispositivo pasa de 5 minutos sin señal', function () {
    [$route, $device] = onRoute(minutesSilent: 8);

    expect(evaluate()['gps_silence'])->toBe(['opened' => 1, 'resolved' => 0]);
    Notification::assertSentToTimes($this->admin, FleetAlert::class, 1);

    // Segunda pasada con la misma situación: no repite ni duplica el aviso.
    evaluate();
    Notification::assertSentToTimes($this->admin, FleetAlert::class, 1);
    expect(AlertEvent::open()->count())->toBe(1)
        ->and(AlertEvent::first()->message)->toContain('sin señal GPS')->toContain($route->driver->user->name);
});

it('avisa también a mantenimiento', function () {
    onRoute(minutesSilent: 8);
    $mantenimiento = makeUser('mantenimiento');

    evaluate();

    Notification::assertSentTo($mantenimiento, FleetAlert::class);
});

it('no avisa si la señal es reciente, si la ruta no está operativa o si no hay ruta hoy', function () {
    onRoute(minutesSilent: 3);                                    // dentro del umbral
    onRoute(minutesSilent: 30, status: RouteStatus::Completed);   // ya terminó: es normal que calle

    $sinRuta = Device::factory()->create(['last_seen_at' => now()->subHours(5)]);
    $sinRuta->update(['driver_id' => makePermanentRoute()->driver_id]);

    evaluate();

    expect(AlertEvent::count())->toBe(0);
    Notification::assertNothingSent();
});

it('al volver la señal resuelve el aviso y lo notifica una vez', function () {
    [$route, $device] = onRoute(minutesSilent: 8);
    evaluate();

    $device->update(['last_seen_at' => now()]);
    expect(evaluate()['gps_silence'])->toBe(['opened' => 0, 'resolved' => 1]);

    Notification::assertSentToTimes($this->admin, FleetAlert::class, 2); // abierto + resuelto
    expect(AlertEvent::open()->count())->toBe(0);

    evaluate();
    Notification::assertSentToTimes($this->admin, FleetAlert::class, 2);
});

it('fuera del horario de tracking no avisa ni cierra los avisos abiertos', function () {
    [$route, $device] = onRoute(minutesSilent: 8);
    evaluate();

    $this->travelTo(now()->setTime(23, 0));
    $device->update(['last_seen_at' => now()->subHours(1)]);
    evaluate();

    // El de señal sigue abierto y sin falso "resuelto" a las 22:00 por el mero hecho de pausarse
    // el tracking (la jornada en curso sí abre, legítimamente, su propio aviso de "sin cerrar").
    expect(AlertEvent::open()->where('rule_type', AlertType::GpsSilence)->count())->toBe(1);
    Notification::assertNotSentTo($this->admin, FleetAlert::class, fn ($n) => $n->kind === 'resolved');
});

it('respeta el umbral configurado', function () {
    [$route, $device] = onRoute(minutesSilent: 10);
    AlertRule::forType(AlertType::GpsSilence)->update(['threshold' => 30]);

    evaluate();
    expect(AlertEvent::count())->toBe(0);

    AlertRule::forType(AlertType::GpsSilence)->update(['threshold' => 5]);
    evaluate();
    expect(AlertEvent::open()->count())->toBe(1);
});

it('con el aviso desactivado no notifica y cierra en silencio los abiertos', function () {
    onRoute(minutesSilent: 8);
    evaluate();
    Notification::assertSentToTimes($this->admin, FleetAlert::class, 1);

    AlertRule::forType(AlertType::GpsSilence)->update(['enabled' => false]);
    evaluate();

    expect(AlertEvent::open()->count())->toBe(0);
    Notification::assertSentToTimes($this->admin, FleetAlert::class, 1); // ni siquiera "resuelto"
});

it('avisa de batería baja con lectura reciente y de nada si la lectura es vieja', function () {
    [$route, $device] = onRoute();
    position($device, ['battery_level' => 9]);

    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::LowBattery)->count())->toBe(1);

    [$route2, $device2] = onRoute();
    position($device2, ['battery_level' => 5, 'recorded_at' => now()->subHours(2)]);

    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::LowBattery)->count())->toBe(1); // solo el primero
});

it('el exceso de velocidad exige dos lecturas seguidas por encima del límite', function () {
    [$route, $device] = onRoute();
    position($device, ['speed_mps' => 40, 'recorded_at' => now()->subMinutes(2)]); // 144 km/h
    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::Speeding)->count())->toBe(0); // un pico suelto

    position($device, ['speed_mps' => 38, 'recorded_at' => now()->subMinute()]);
    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::Speeding)->count())->toBe(1);

    position($device, ['speed_mps' => 10, 'recorded_at' => now()]);
    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::Speeding)->count())->toBe(0); // vuelve a la normalidad
});

it('avisa de una jornada sin empezar solo pasada la hora y con paradas pendientes', function () {
    [$route] = onRoute(status: RouteStatus::Published);
    RouteStop::factory()->for($route, 'route')->create(['status' => RouteStopStatus::Pending]);

    evaluate(); // 10:00 ≥ 09:00 → avisa
    expect(AlertEvent::open()->where('rule_type', AlertType::ShiftNotStarted)->count())->toBe(1);

    $route->update(['status' => RouteStatus::InProgress, 'liter_meter_start' => 1000]);
    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::ShiftNotStarted)->count())->toBe(0);
});

it('no avisa de jornada sin empezar antes de la hora ni sin paradas pendientes', function () {
    $this->travelTo(now()->setTime(8, 0));
    [$route] = onRoute(status: RouteStatus::Published);
    RouteStop::factory()->for($route, 'route')->create(['status' => RouteStopStatus::Pending]);
    evaluate();
    expect(AlertEvent::count())->toBe(0);

    $this->travelTo(now()->setTime(11, 0));
    [$vacia] = onRoute(status: RouteStatus::Published); // sin paradas
    evaluate();
    expect(AlertEvent::where('rule_type', AlertType::ShiftNotStarted)->where('subject_id', $vacia->id)->count())->toBe(0);
});

it('avisa de una jornada sin cerrar: la de hoy pasada la hora y las de días anteriores siempre', function () {
    [$hoy] = onRoute(status: RouteStatus::InProgress);

    evaluate(); // 10:00 < 21:00
    expect(AlertEvent::open()->where('rule_type', AlertType::ShiftNotClosed)->count())->toBe(0);

    $ayer = makeRoute(today()->subDay()->toDateString());
    $ayer->update(['status' => RouteStatus::InProgress]);
    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::ShiftNotClosed)->count())->toBe(1);

    $this->travelTo(now()->setTime(21, 30));
    evaluate();
    expect(AlertEvent::open()->where('rule_type', AlertType::ShiftNotClosed)->count())->toBe(2);
});

it('el comando avisos:evaluar ejecuta la evaluación', function () {
    onRoute(minutesSilent: 8);

    $this->artisan('avisos:evaluar')->assertSuccessful();

    expect(AlertEvent::open()->count())->toBe(1);
});

it('administrador y mantenimiento entran en /avisos; el chofer no', function () {
    $this->actingAs($this->admin)->get('/avisos')->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get('/avisos')->assertOk();
    $this->actingAs(makeUser('chofer'))->get('/avisos')->assertForbidden();
});

it('guarda la configuración y la pantalla la recarga con los valores nuevos', function () {
    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('form.items.gps_silence.threshold', 12)
        ->set('form.items.low_battery.enabled', false)
        ->set('form.items.shift_not_started.time', '08:30')
        ->call('save')
        ->assertHasNoErrors();

    expect(AlertRule::forType(AlertType::GpsSilence)->threshold)->toBe(12)
        ->and(AlertRule::forType(AlertType::LowBattery)->enabled)->toBeFalse()
        ->and(AlertRule::forType(AlertType::ShiftNotStarted)->time_of_day)->toBe('08:30');

    Livewire::actingAs($this->admin)->test(Index::class)
        ->assertSet('form.items.gps_silence.threshold', 12);
});

it('rechaza umbrales fuera de rango y el mensaje llega al HTML', function () {
    $test = Livewire::actingAs($this->admin)->test(Index::class)
        ->set('form.items.gps_silence.threshold', 0)
        ->set('form.items.shift_not_closed.time', '25:99')
        ->call('save')
        ->assertHasErrors(['form.items.gps_silence.threshold', 'form.items.shift_not_closed.time']);

    expect($test->html())->toContain('Debe estar entre 1 y 240')->toContain('La hora no es válida');
    expect(AlertRule::forType(AlertType::GpsSilence)->threshold)->toBe(5);
});

it('la pantalla no tiene botón de evaluar y se refresca sola por poll', function () {
    $html = Livewire::actingAs($this->admin)->test(Index::class)->html();

    expect($html)->not->toContain('Evaluar ahora')
        ->and($html)->toContain('wire:poll.10s')
        ->and($html)->toContain('Se actualiza solo');
});

it('al abrir la pantalla evalúa sola (sin planificador ni botón) y muestra los avisos activos', function () {
    onRoute(minutesSilent: 8);
    expect(AlertEvent::count())->toBe(0);

    $test = Livewire::actingAs($this->admin)->test(Index::class);

    expect(AlertEvent::open()->count())->toBe(1);
    expect($test->html())->toContain('sin señal GPS')->toContain('última comprobación a las 10:00:00');
});

it('un poll posterior detecta un aviso nuevo en cuanto la evaluación anterior es antigua', function () {
    [$route, $device] = onRoute(minutesSilent: 1);
    $test = Livewire::actingAs($this->admin)->test(Index::class);
    expect(AlertEvent::count())->toBe(0);

    // El dispositivo se calla; pasa más de un minuto y llega el siguiente poll de la pantalla.
    $device->update(['last_seen_at' => now()->subMinutes(7)]);
    $this->travel(60)->seconds();
    $test->call('$refresh');

    expect(AlertEvent::open()->count())->toBe(1);
});

it('la evaluación perezosa no se repite si la anterior es reciente ni mientras otra está en curso', function () {
    onRoute(minutesSilent: 8);
    $evaluator = app(AlertEvaluator::class);

    expect($evaluator->runGuarded(45))->not->toBeNull();  // primera: evalúa
    expect($evaluator->runGuarded(45))->toBeNull();       // reciente: se salta

    $this->travel(46)->seconds();
    $lock = Cache::lock('alerts:evaluate', 55);
    $lock->get();
    expect($evaluator->runGuarded(45))->toBeNull();       // otra pasada en curso: se salta
    $lock->release();

    expect($evaluator->runGuarded(45))->not->toBeNull();  // libre y antigua: evalúa
});

it('un fallo del evaluador no tira la pantalla de avisos', function () {
    $this->mock(AlertEvaluator::class, function ($mock) {
        $mock->shouldReceive('runGuarded')->andThrow(new RuntimeException('boom'));
        $mock->shouldReceive('lastRunAt')->andReturn(null);
    });

    Livewire::actingAs($this->admin)->test(Index::class)->assertOk()->assertSee('Todo en orden');
});

it('las insignias del menú son componentes vivos con su propio poll y respetan permisos', function () {
    AlertEvent::create(['rule_type' => AlertType::GpsSilence, 'subject_type' => 'device', 'subject_id' => 1,
        'subject_label' => 'X', 'message' => 'm', 'opened_at' => now()]);
    AlertEvent::create(['rule_type' => AlertType::GpsSilence, 'subject_type' => 'device', 'subject_id' => 2,
        'subject_label' => 'Y', 'message' => 'm', 'opened_at' => now()]);

    $test = Livewire::actingAs($this->admin)->test(NavBadge::class, ['kind' => 'alerts']);
    expect($test->html())->toContain('wire:poll.20s')->toContain('>2<');

    // Un aviso nuevo aparece en la insignia en el siguiente poll, sin recargar la página.
    AlertEvent::create(['rule_type' => AlertType::LowBattery, 'subject_type' => 'device', 'subject_id' => 3,
        'subject_label' => 'Z', 'message' => 'm', 'opened_at' => now()]);
    expect($test->call('$refresh')->html())->toContain('>3<');

    // Un chofer no ve ningún número.
    $chofer = Livewire::actingAs(makeUser('chofer'))->test(NavBadge::class, ['kind' => 'alerts']);
    expect($chofer->html())->not->toContain('>3<')->toContain('wire:poll.20s');
});

it('el mapa usa el mismo umbral que el aviso de señal', function () {
    AlertRule::forType(AlertType::GpsSilence)->update(['threshold' => 20]);

    expect(Livewire::actingAs($this->admin)->test(Map::class)->instance()->staleMinutes())->toBe(20);
});

/**
 * Marca la evaluación como recién hecha: la pantalla /avisos evalúa sola al abrirse si la última pasada
 * es antigua, y los avisos "activos" de fixture (sin dispositivo real detrás) se cerrarían como resueltos.
 */
function evaluatorJustRan(): void
{
    Cache::forever(AlertEvaluator::LAST_RUN_KEY, now()->timestamp);
}

/** Aviso ya resuelto hace $daysAgo días (o abierto si es null). */
function alertEvent(?int $resolvedDaysAgo, AlertType $type = AlertType::GpsSilence, string $label = 'Pedro'): AlertEvent
{
    return AlertEvent::create([
        'rule_type' => $type,
        'subject_type' => 'device',
        'subject_id' => fake()->unique()->numberBetween(1, 99999),
        'subject_label' => $label,
        'message' => "{$label}: aviso",
        'opened_at' => now()->subDays(($resolvedDaysAgo ?? 0) + 1),
        'resolved_at' => $resolvedDaysAgo === null ? null : now()->subDays($resolvedDaysAgo),
    ]);
}

it('avisos:purgar borra los resueltos antiguos y nunca los activos ni los recientes', function () {
    $viejo = alertEvent(45);
    $reciente = alertEvent(5);
    $activoViejo = alertEvent(null);
    $activoViejo->update(['opened_at' => now()->subDays(90)]); // lleva meses abierto: sigue vigente

    $this->artisan('avisos:purgar')->assertSuccessful(); // retención por defecto: 30 días

    expect(AlertEvent::find($viejo->id))->toBeNull()
        ->and(AlertEvent::find($reciente->id))->not->toBeNull()
        ->and(AlertEvent::find($activoViejo->id))->not->toBeNull();
});

it('avisos:purgar respeta --dias', function () {
    $a = alertEvent(10);

    $this->artisan('avisos:purgar --dias=7')->assertSuccessful();

    expect(AlertEvent::find($a->id))->toBeNull();
});

it('el historial pagina, filtra por tipo y no muestra los activos', function () {
    evaluatorJustRan();
    foreach (range(1, 20) as $i) {
        alertEvent(1, AlertType::GpsSilence, "Silencio {$i}");
    }
    alertEvent(1, AlertType::LowBattery, 'Batería Luis');
    alertEvent(null, AlertType::GpsSilence, 'Activo Ana');

    $test = Livewire::actingAs($this->admin)->test(Index::class);

    expect($test->viewData('history')->total())->toBe(21)
        ->and($test->viewData('history')->count())->toBe(15)   // 15 por página, no un tope total
        ->and($test->viewData('resolvedTotal'))->toBe(21);

    $test->set('historyType', 'low_battery');
    expect($test->viewData('history')->total())->toBe(1);
    expect($test->html())->toContain('Batería Luis')->not->toContain('Silencio 1');
});

it('se pueden eliminar avisos resueltos seleccionados, pero nunca uno activo', function () {
    evaluatorJustRan();
    $a = alertEvent(1, label: 'Resuelto A');
    $b = alertEvent(1, label: 'Resuelto B');
    $activo = alertEvent(null, label: 'Activo');

    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('selected', [$a->id, $activo->id]) // intento colar un activo en la selección
        ->call('deleteSelected')
        ->assertDispatched('toast');

    expect(AlertEvent::find($a->id))->toBeNull()
        ->and(AlertEvent::find($b->id))->not->toBeNull()
        ->and(AlertEvent::find($activo->id))->not->toBeNull();
});

it('"Vaciar esta lista" borra solo los resueltos que cumplen el filtro', function () {
    evaluatorJustRan();
    alertEvent(1, AlertType::GpsSilence, 'Silencio');
    $bateria = alertEvent(1, AlertType::LowBattery, 'Batería');
    $activo = alertEvent(null, AlertType::GpsSilence, 'Activo');

    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('historyType', 'gps_silence')
        ->call('deleteFiltered');

    expect(AlertEvent::where('rule_type', AlertType::GpsSilence)->whereNotNull('resolved_at')->count())->toBe(0)
        ->and(AlertEvent::find($bateria->id))->not->toBeNull()
        ->and(AlertEvent::find($activo->id))->not->toBeNull();
});

it('seleccionar todos marca solo los de la página visible', function () {
    foreach (range(1, 20) as $i) {
        alertEvent(1, label: "Aviso {$i}");
    }

    $test = Livewire::actingAs($this->admin)->test(Index::class)->call('toggleSelectAll');
    expect($test->get('selected'))->toHaveCount(15);

    $test->call('toggleSelectAll'); // segunda vez: desmarca
    expect($test->get('selected'))->toHaveCount(0);
});

it('un chofer no puede borrar avisos', function () {
    $a = alertEvent(1);

    Livewire::actingAs(makeUser('chofer'))->test(Index::class)->assertForbidden();

    expect(AlertEvent::find($a->id))->not->toBeNull();
});

it('el historial explica la retención automática', function () {
    $test = Livewire::actingAs($this->admin)->test(Index::class);

    expect($test->html())->toContain('se borran solos a los 30 días')->toContain('nunca se borran');
});
