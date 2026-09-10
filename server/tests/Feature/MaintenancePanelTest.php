<?php

use App\Livewire\Maintenance\Audits;
use App\Livewire\Maintenance\Errors;
use App\Livewire\Maintenance\SystemLog;
use App\Models\Driver;
use App\Models\ErrorLog;
use App\Models\Route;
use App\Models\Truck;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

function seedAudit(array $overrides = []): Audit
{
    return Audit::create(array_merge([
        'user_type' => User::class,
        'user_id' => null,
        'event' => 'updated',
        'auditable_type' => Route::class,
        'auditable_id' => 1,
        'old_values' => ['status' => 'draft'],
        'new_values' => ['status' => 'published'],
        'url' => 'http://localhost/rutas',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

describe('acceso', function () {
    it('deja el panel de mantenimiento solo al rol mantenimiento', function () {
        $this->actingAs(makeUser('mantenimiento'));
        $this->get('/mantenimiento/auditoria')->assertOk();
        $this->get('/mantenimiento/errores')->assertOk();
        $this->get('/mantenimiento/log')->assertOk();
        $this->get('/mantenimiento/accesos')->assertOk();
        $this->get('/mantenimiento')->assertOk()->assertSee('Resumen');
    });

    it('bloquea a administrador y chofer', function () {
        $this->actingAs(makeUser('administrador'))->get('/mantenimiento/auditoria')->assertForbidden();

        $chofer = makeUser('chofer');
        Driver::factory()->create(['user_id' => $chofer->id]);
        $this->actingAs($chofer)->get('/mantenimiento/errores')->assertForbidden();
    });
});

describe('auditoría', function () {
    beforeEach(fn () => $this->actingAs(makeUser('mantenimiento')));

    it('filtra por modelo y por evento', function () {
        seedAudit(['auditable_type' => Route::class, 'event' => 'updated']);
        seedAudit(['auditable_type' => Truck::class, 'event' => 'created', 'old_values' => [], 'new_values' => ['code' => 'C-9']]);

        Livewire::test(Audits::class)
            ->assertSee('Ruta')
            ->assertSee('Camión')
            ->set('model', Truck::class)
            ->assertSee('Camión')
            ->assertDontSee('#1 ')
            ->set('model', 'all')
            ->set('event', 'created')
            ->assertSet('event', 'created');
    });

    it('filtra por el evento "Inicio de sesión"', function () {
        $login = seedAudit(['event' => 'login', 'auditable_type' => User::class, 'old_values' => [], 'new_values' => []]);
        seedAudit(['auditable_type' => Route::class, 'event' => 'updated']);

        Livewire::test(Audits::class)
            ->assertSee('Inicio de sesión')
            ->set('event', 'login')
            ->assertSee('Inicio de sesión')
            ->assertSee('Usuario')
            ->assertViewHas('audits', fn ($audits) => $audits->pluck('id')->all() === [$login->id]);
    });

    it('busca por nombre de usuario', function () {
        $ana = makeUser('administrador');
        $ana->update(['name' => 'Ana Buscable']);
        seedAudit(['user_id' => $ana->id]);
        seedAudit(['user_id' => null]);

        Livewire::test(Audits::class)
            ->set('search', 'Buscable')
            ->assertSee('Ana Buscable');
    });

    it('abre el detalle con los campos modificados', function () {
        $audit = seedAudit(['old_values' => ['status' => 'draft'], 'new_values' => ['status' => 'published']]);

        Livewire::test(Audits::class)
            ->call('show', $audit->id)
            ->assertDispatched('open-modal', 'audit-detail')
            ->assertSee('status')
            ->assertSee('published');
    });
});

describe('errores', function () {
    beforeEach(fn () => $this->actingAs(makeUser('mantenimiento')));

    it('busca y muestra el detalle', function () {
        ErrorLog::create(['level' => 'error', 'message' => 'Fallo raro de prueba', 'exception_class' => 'RuntimeException', 'file' => 'app/X.php', 'line' => 10, 'occurred_at' => now(), 'url' => 'http://localhost/rutas', 'method' => 'GET']);
        ErrorLog::create(['level' => 'error', 'message' => 'Otro fallo', 'exception_class' => 'TypeError', 'file' => 'app/Y.php', 'line' => 20, 'occurred_at' => now(), 'method' => 'GET']);

        Livewire::test(Errors::class)
            ->set('search', 'raro de prueba')
            ->assertSee('Fallo raro de prueba')
            ->assertDontSee('Otro fallo');
    });

    it('purga los errores de más de 30 días', function () {
        ErrorLog::create(['level' => 'error', 'message' => 'viejo', 'occurred_at' => now()->subDays(45), 'method' => 'GET']);
        ErrorLog::create(['level' => 'error', 'message' => 'reciente', 'occurred_at' => now()->subDays(3), 'method' => 'GET']);

        Livewire::test(Errors::class)->call('purgeOld');

        expect(ErrorLog::count())->toBe(1)
            ->and(ErrorLog::first()->message)->toBe('reciente');
    });

    it('elimina un registro concreto', function () {
        $log = ErrorLog::create(['level' => 'error', 'message' => 'a borrar', 'occurred_at' => now(), 'method' => 'GET']);

        Livewire::test(Errors::class)->call('deleteLog', $log->id);

        expect(ErrorLog::find($log->id))->toBeNull();
    });
});

describe('log de la aplicación', function () {
    beforeEach(fn () => $this->actingAs(makeUser('mantenimiento')));

    it('lee y filtra las entradas del archivo', function () {
        $name = 'pest-'.uniqid().'.log';
        $path = storage_path('logs/'.$name);
        File::put($path, implode("\n", [
            '[2026-09-06 10:00:00] testing.INFO: Arranque del sistema',
            '[2026-09-06 10:05:00] testing.ERROR: Algo ha explotado {"exception":"boom"}',
            'Stack trace:',
            '#0 /app/foo.php(12)',
        ])."\n");

        try {
            Livewire::test(SystemLog::class)
                ->set('file', $name)
                ->assertSee('Algo ha explotado')
                ->assertSee('Arranque del sistema')
                ->set('level', 'error')
                ->assertSee('Algo ha explotado')
                ->assertDontSee('Arranque del sistema');
        } finally {
            File::delete($path);
        }
    });

    it('rechaza rutas fuera de storage/logs', function () {
        Livewire::test(SystemLog::class)
            ->set('file', '../../.env')
            ->assertSet('file', '../../.env')
            ->assertSee('Sin líneas que mostrar');
    });
});
