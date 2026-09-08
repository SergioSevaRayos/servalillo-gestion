<?php

use App\Livewire\Maintenance\Overview;
use App\Models\Driver;
use App\Models\ErrorLog;
use App\Models\SupportTicket;
use Livewire\Livewire;

it('redirige a mantenimiento al panel de mantenimiento desde /home', function () {
    $this->actingAs(makeUser('mantenimiento'))
        ->get('/home')
        ->assertRedirect(route('maintenance.index'));
});

it('el administrador sigue yendo al panel estadístico desde /home', function () {
    $this->actingAs(makeUser('administrador'))
        ->get('/home')
        ->assertRedirect(route('dashboard'));
});

it('el chofer sigue yendo a su ruta desde /home', function () {
    $user = makeUser('chofer');
    Driver::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/home')->assertRedirect(route('chofer.today'));
});

it('el panel de mantenimiento muestra incidencias, errores y actividad', function () {
    $maint = makeUser('mantenimiento');
    $admin = makeUser('administrador');

    SupportTicket::factory()->create(['user_id' => $admin->id, 'subject' => 'Incidencia visible', 'status' => 'abierto']);
    SupportTicket::factory()->create(['user_id' => $admin->id, 'subject' => 'Ya resuelta', 'status' => 'resuelto']);
    ErrorLog::create([
        'level' => 'error', 'message' => 'Boom en producción', 'exception_class' => 'RuntimeException',
        'file' => 'app/Foo.php', 'line' => 10, 'context' => [], 'occurred_at' => now(),
    ]);

    Livewire::actingAs($maint)->test(Overview::class)
        ->assertOk()
        ->assertSee('Incidencias abiertas')
        ->assertSee('Errores (7 días)')
        ->assertSee('Incidencia visible')
        ->assertDontSee('Ya resuelta')
        ->assertSee('RuntimeException')
        ->assertSee('Resumen'); // pestaña
});

it('un administrador no accede al panel de mantenimiento', function () {
    $this->actingAs(makeUser('administrador'))->get('/mantenimiento')->assertForbidden();

    Livewire::actingAs(makeUser('administrador'))->test(Overview::class)->assertForbidden();
});
