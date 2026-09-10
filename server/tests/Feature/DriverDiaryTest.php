<?php

use App\Livewire\Drivers\Diary;
use App\Models\Driver;
use App\Models\DriverLog;
use App\Models\User;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

function driverFor(): Driver
{
    return Driver::factory()->for(User::factory()->state(['name' => 'Pedro Ramírez']), 'user')->create();
}

test('un chofer no puede acceder al diario', function () {
    $driver = driverFor();

    $this->actingAs(makeUser('chofer'))->get(route('drivers.diary', $driver))->assertForbidden();
});

test('el administrador anota una incidencia nueva', function () {
    $driver = driverFor();
    $admin = makeUser('administrador');

    Livewire::actingAs($admin)
        ->test(Diary::class, ['driver' => $driver])
        ->call('create')
        ->set('form.category', 'positive')
        ->set('form.body', 'Entregó todas las paradas antes de tiempo.')
        ->call('save')
        ->assertHasNoErrors();

    $log = DriverLog::firstWhere('driver_id', $driver->id);
    expect($log)->not->toBeNull()
        ->and($log->category->value)->toBe('positive')
        ->and($log->body)->toBe('Entregó todas las paradas antes de tiempo.')
        ->and($log->created_by)->toBe($admin->id)
        ->and($log->updated_by)->toBeNull()
        ->and($log->wasEdited())->toBeFalse();
});

test('editar una incidencia anota quién y qué ha cambiado', function () {
    $driver = driverFor();
    $author = makeUser('administrador');
    $editor = makeUser('mantenimiento');

    $log = DriverLog::factory()->for($driver)->create([
        'category' => 'negative',
        'body' => 'Llegó tarde a la primera parada.',
        'created_by' => $author->id,
    ]);

    Livewire::actingAs($editor)
        ->test(Diary::class, ['driver' => $driver])
        ->call('edit', $log)
        ->set('form.category', 'neutral')
        ->set('form.body', 'Llegó tarde a la primera parada; tráfico en la N-340.')
        ->call('save')
        ->assertHasNoErrors();

    $log->refresh();
    expect($log->category->value)->toBe('neutral')
        ->and($log->body)->toContain('tráfico')
        ->and($log->updated_by)->toBe($editor->id)
        ->and($log->wasEdited())->toBeTrue();

    // El detalle campo a campo de qué cambió lo da owen-it/laravel-auditing (Audit::getModified()),
    // igual que en el resto de la app — desactivado en consola (config/audit.php), así que no se
    // puede comprobar aquí; sí se ve en /mantenimiento/auditoria en producción.
});

test('eliminar una incidencia la hace desaparecer del diario', function () {
    $driver = driverFor();
    $log = DriverLog::factory()->for($driver)->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Diary::class, ['driver' => $driver])
        ->call('delete', $log)
        ->assertHasNoErrors();

    expect($log->fresh()->trashed())->toBeTrue();
});

test('el filtro de categoría y la búsqueda funcionan', function () {
    $driver = driverFor();
    DriverLog::factory()->for($driver)->create(['category' => 'positive', 'body' => 'Cliente satisfecho, felicitó al chofer.']);
    DriverLog::factory()->for($driver)->create(['category' => 'negative', 'body' => 'Queja por retraso.']);

    $c = Livewire::actingAs(makeUser('administrador'))->test(Diary::class, ['driver' => $driver]);

    $c->assertSee('felicitó')->assertSee('Queja');

    $c->set('category', 'positive')->assertSee('felicitó')->assertDontSee('Queja');
    $c->set('category', 'all')->set('search', 'retraso')->assertDontSee('felicitó')->assertSee('Queja');
});

test('"Ver cambios" no revienta cuando el campo tocado es un enum (categoría)', function () {
    // El auditing real está desactivado en consola (config/audit.php), así que se inserta el
    // Audit a mano — igual que MaintenancePanelTest::seedAudit() — para poder probar que la
    // vista renderiza un cambio de "category" (casteado a DriverLogCategory, un enum nativo) sin
    // que (string) $enum reviente la plantilla — bug real visto en producción.
    $driver = driverFor();
    $log = DriverLog::factory()->for($driver)->create(['category' => 'neutral']);

    Audit::create([
        'user_type' => User::class,
        'user_id' => null,
        'event' => 'updated',
        'auditable_type' => DriverLog::class,
        'auditable_id' => $log->id,
        'old_values' => ['category' => 'negative'],
        'new_values' => ['category' => 'neutral'],
        'url' => 'http://localhost/chofers',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Diary::class, ['driver' => $driver])
        ->call('viewHistory', $log->id)
        ->assertOk()
        ->assertSee('Categoría')
        ->assertSee('Negativa')
        ->assertSee('Neutra');
});

test('el historial solo muestra los campos con contenido, sin nulls ni el evento de creación', function () {
    $driver = driverFor();
    $log = DriverLog::factory()->for($driver)->create();

    // Evento "created" real (con id/driver_id/created_by/etc.) — no debe aparecer, ya lo resume
    // la cabecera "Creada por…".
    Audit::create([
        'user_type' => User::class, 'user_id' => null, 'event' => 'created',
        'auditable_type' => DriverLog::class, 'auditable_id' => $log->id,
        'old_values' => [], 'new_values' => ['id' => $log->id, 'driver_id' => $driver->id, 'body' => $log->body],
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // "updated" que solo toca updated_by (p. ej. un resave sin cambios de contenido) — sin
    // campos relevantes que mostrar, la tarjeta entera se descarta.
    Audit::create([
        'user_type' => User::class, 'user_id' => null, 'event' => 'updated',
        'auditable_type' => DriverLog::class, 'auditable_id' => $log->id,
        'old_values' => ['updated_by' => null], 'new_values' => ['updated_by' => 1],
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Diary::class, ['driver' => $driver])
        ->call('viewHistory', $log->id)
        ->assertOk()
        ->assertDontSee('driver_id')
        ->assertDontSee('updated_by')
        ->assertSee('Sin cambios editados todavía.');
});
