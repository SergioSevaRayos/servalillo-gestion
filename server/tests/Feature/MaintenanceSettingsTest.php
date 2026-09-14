<?php

use App\Livewire\Maintenance\Settings;
use App\Models\CompanySetting;
use Livewire\Livewire;

it('mantenimiento ve la página de ajustes', function () {
    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Settings::class)
        ->assertOk();
});

it('un administrador no puede acceder a los ajustes de mantenimiento', function () {
    Livewire::actingAs(makeUser('administrador'))
        ->test(Settings::class)
        ->assertStatus(403);
});

it('un chofer no puede acceder a los ajustes de mantenimiento', function () {
    Livewire::actingAs(makeUser('chofer'))
        ->test(Settings::class)
        ->assertStatus(403);
});

it('la ruta real exige el rol mantenimiento', function () {
    $this->actingAs(makeUser('administrador'))->get(route('maintenance.settings'))->assertForbidden();
});

it('sin ajuste guardado, precarga los valores por defecto de la config', function () {
    config()->set('servalillo.base.latitude', 36.5);
    config()->set('servalillo.base.longitude', -2.5);
    config()->set('servalillo.dwell.exclude_base_radius_meters', 150);
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Settings::class)
        ->assertSet('base_latitude', '36.5')
        ->assertSet('base_longitude', '-2.5')
        ->assertSet('base_radius_meters', '150')
        ->assertSet('unplanned_stop_minutes', '5');
});

it('guarda la ubicación de la base, su radio y el umbral de parada no programada', function () {
    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Settings::class)
        ->set('base_latitude', '40.4168')
        ->set('base_longitude', '-3.7038')
        ->set('base_radius_meters', '250')
        ->set('unplanned_stop_minutes', '10')
        ->call('save')
        ->assertHasNoErrors();

    $setting = CompanySetting::current();
    expect((float) $setting->base_latitude)->toEqual(40.4168)
        ->and((float) $setting->base_longitude)->toEqual(-3.7038)
        ->and($setting->base_radius_meters)->toBe(250)
        ->and($setting->unplanned_stop_minutes)->toBe(10)
        ->and((float) config('servalillo.base.latitude'))->toEqual(40.4168)
        ->and((int) config('servalillo.dwell.exclude_base_radius_meters'))->toBe(250)
        ->and((int) config('servalillo.dwell.unplanned_stop_min_seconds'))->toBe(600);
});

it('rechaza un umbral de parada no programada fuera de rango', function () {
    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Settings::class)
        ->set('unplanned_stop_minutes', '200')
        ->call('save')
        ->assertHasErrors(['unplanned_stop_minutes']);
});

it('rechaza un radio de base fuera de rango', function () {
    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Settings::class)
        ->set('base_radius_meters', '1')
        ->call('save')
        ->assertHasErrors(['base_radius_meters']);
});

it('una vez guardado, un nuevo montaje carga los valores guardados, no los del .env', function () {
    CompanySetting::current()->update([
        'base_latitude' => 41.0, 'base_longitude' => -1.0, 'base_radius_meters' => 300, 'unplanned_stop_minutes' => 7,
    ]);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Settings::class)
        ->assertSet('base_latitude', fn ($v) => (float) $v === 41.0)
        ->assertSet('base_longitude', fn ($v) => (float) $v === -1.0)
        ->assertSet('base_radius_meters', '300')
        ->assertSet('unplanned_stop_minutes', '7');
});
