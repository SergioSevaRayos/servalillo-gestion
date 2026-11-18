<?php

use App\Livewire\Notifications\Bell;
use Livewire\Livewire;

it('el desplegable de la campana se alinea a la derecha por defecto (barra móvil)', function () {
    $html = Livewire::actingAs(makeUser('administrador'))->test(Bell::class)->html();

    expect($html)->toContain('ltr:origin-top-right')->toContain('end-0')->not->toContain('ltr:origin-top-left');
});

it('la campana del menú lateral abre su desplegable hacia la derecha, sin salirse de la ventana', function () {
    $html = Livewire::actingAs(makeUser('administrador'))->test(Bell::class, ['align' => 'left'])->html();

    expect($html)->toContain('ltr:origin-top-left')->toContain('start-0')->not->toContain('ltr:origin-top-right');
});

it('la campana del lateral y la de la barra móvil se renderizan con su alineación', function () {
    $html = $this->actingAs(makeUser('administrador'))->get('/dashboard')->getContent();

    expect($html)->toContain('&quot;align&quot;:&quot;left&quot;')   // menú lateral (escritorio)
        ->toContain('&quot;align&quot;:&quot;right&quot;')           // barra superior móvil
        ->not->toContain('sidebar-toggle');                         // sin botón de ocultar el menú
});
