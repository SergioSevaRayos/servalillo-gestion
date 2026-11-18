<?php

/**
 * Menú móvil (<x-ui.drop-menu>): con tantas opciones el panel se salía por arriba de la pantalla y la página
 * de detrás seguía desplazándose con el menú abierto.
 */
it('el panel del menú móvil tiene alto máximo, scroll propio y no encadena el scroll con la página', function () {
    $html = $this->actingAs(makeUser('administrador'))->get('/dashboard')->getContent();

    expect($html)->toContain('drop-menu-panel')
        ->toContain('overflow-y-auto')
        ->toContain('overscroll-contain');
});

it('mientras el menú móvil está abierto se bloquea el scroll de la página y se libera al cerrar o navegar', function () {
    $html = $this->actingAs(makeUser('administrador'))->get('/dashboard')->getContent();

    expect($html)
        ->toContain("document.documentElement.classList.toggle('menu-open', open)")
        ->toContain("document.body.classList.toggle('menu-open', open)")
        ->toContain('livewire:navigating.window')   // se cierra al navegar
        ->toContain('touch-none');                  // el fondo no deja pasar gestos de scroll
});

it('la regla de bloqueo y la del panel existen en el CSS compilado', function () {
    $css = collect(glob(public_path('build/assets/app-*.css')))->map(fn ($f) => file_get_contents($f))->implode('');

    expect($css)->toContain('html.menu-open')
        ->toContain('body.menu-open')
        ->toContain('.drop-menu-panel');

    // Alto real visible (dvh) con respaldo en vh para navegadores que no lo entienden.
    expect($css)->toContain('100dvh - 7.5rem')
        ->toContain('@supports not (height:100dvh)')
        ->toContain('100vh - 7.5rem');
});

it('el menú móvil lista todas las opciones del rol, incluidas las nuevas', function () {
    $html = $this->actingAs(makeUser('mantenimiento'))->get('/mantenimiento')->getContent();

    foreach (['Mapa', 'Avisos', 'Facturas', 'Mantenimiento', 'Guía de estilo', 'Cerrar sesión'] as $label) {
        expect($html)->toContain($label);
    }
});
