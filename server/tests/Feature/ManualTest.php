<?php

use App\Livewire\Manual\Show;
use Livewire\Livewire;

it('redirige a login a los invitados', function () {
    $this->get(route('manual'))->assertRedirect(route('login'));
});

it('el chofer ve su manual y no puede abrir el de administración', function () {
    $this->actingAs(makeUser('chofer'));

    $this->get(route('manual'))->assertOk()->assertSee('Manual de uso');
    $this->get(route('manual', 'chofer'))->assertOk();
    $this->get(route('manual', 'administracion'))->assertForbidden();

    Livewire::test(Show::class)->assertSet('type', 'chofer');
});

it('administrador y mantenimiento ven los dos manuales', function (string $role) {
    $this->actingAs(makeUser($role));

    $this->get(route('manual'))->assertOk();
    $this->get(route('manual', 'administracion'))->assertOk();
    $this->get(route('manual', 'chofer'))->assertOk();

    Livewire::test(Show::class)->assertSet('type', 'administracion');
})->with(['administrador', 'mantenimiento']);

it('un manual desconocido da 404', function () {
    $this->actingAs(makeUser('administrador'));

    $this->get('/manual/otro')->assertNotFound();
});

it('renderiza el markdown con índice y sin HTML crudo', function () {
    $this->actingAs(makeUser('administrador'));

    $component = Livewire::test(Show::class, ['type' => 'chofer']);
    $manual = $component->instance()->manual();

    expect($manual['html'])->not->toContain('<script');
});

it('el menú enlaza al manual para cada rol', function (string $role) {
    $this->actingAs(makeUser($role));

    $this->get($role === 'chofer' ? route('chofer.today') : route('dashboard'))
        ->assertSee(route('manual'));
})->with(['chofer', 'administrador']);
