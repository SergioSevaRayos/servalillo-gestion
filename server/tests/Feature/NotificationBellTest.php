<?php

use App\Livewire\Notifications\Bell;
use App\Models\Driver;
use App\Notifications\ChoferRouteChanged;
use Livewire\Livewire;

it('muestra el contador de no leídas y refresca por poll', function () {
    $admin = makeUser('administrador');
    $admin->notify(new ChoferRouteChanged('stop_failed', 'Pedro', today()->toDateString(), 'Bar Central'));

    Livewire::actingAs($admin)->test(Bell::class)
        ->assertSee('1')
        ->assertSee('Parada fallida')
        ->assertSeeHtml('wire:poll.30s');
});

it('markRead marca la notificación como leída y redirige a su url', function () {
    $admin = makeUser('administrador');
    $admin->notify(new ChoferRouteChanged('client_added', 'Pedro', '2026-09-10', 'Cafetería'));
    $id = $admin->notifications()->first()->id;

    Livewire::actingAs($admin)->test(Bell::class)
        ->call('markRead', $id)
        ->assertRedirect(route('routes.board', ['date' => '2026-09-10']));

    expect($admin->notifications()->first()->read_at)->not->toBeNull();
});

it('markAllRead vacía el contador', function () {
    $admin = makeUser('administrador');
    $admin->notify(new ChoferRouteChanged('stop_failed', 'Pedro', today()->toDateString(), 'A'));
    $admin->notify(new ChoferRouteChanged('stop_skipped', 'Pedro', today()->toDateString(), 'B'));

    Livewire::actingAs($admin)->test(Bell::class)
        ->call('markAllRead');

    expect($admin->unreadNotifications()->count())->toBe(0);
});

it('deleteOne elimina una notificación concreta', function () {
    $admin = makeUser('administrador');
    $admin->notify(new ChoferRouteChanged('stop_failed', 'Pedro', today()->toDateString(), 'A'));
    $admin->notify(new ChoferRouteChanged('stop_skipped', 'Pedro', today()->toDateString(), 'B'));
    $id = $admin->notifications()->first()->id;

    Livewire::actingAs($admin)->test(Bell::class)->call('deleteOne', $id);

    expect($admin->notifications()->count())->toBe(1)
        ->and($admin->notifications()->whereKey($id)->exists())->toBeFalse();
});

it('clearAll elimina todas las notificaciones', function () {
    $admin = makeUser('administrador');
    $admin->notify(new ChoferRouteChanged('stop_failed', 'Pedro', today()->toDateString(), 'A'));
    $admin->notify(new ChoferRouteChanged('client_added', 'Pedro', today()->toDateString(), 'B'));

    Livewire::actingAs($admin)->test(Bell::class)->call('clearAll');

    expect($admin->notifications()->count())->toBe(0);
});

it('la campana no se monta para el chofer', function () {
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);

    $this->actingAs($chofer)->get('/chofer/ruta')
        ->assertOk()
        ->assertDontSeeHtml('wire:poll.30s');
});
