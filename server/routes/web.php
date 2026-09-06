<?php

use App\Http\Controllers\ThemeController;
use App\Livewire\Drivers\Index as DriversIndex;
use App\Livewire\Routes\Board as RoutesBoard;
use App\Livewire\Routes\Index as RoutesIndex;
use App\Livewire\Trucks\Index as TrucksIndex;
use App\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
| No hay landing pública: la raíz lleva al login (o al panel si ya hay sesión).
*/
Route::get('/', function () {
    return redirect(Auth::check() ? route('home') : route('login'));
});

/*
| Preferencia de tema (claro/oscuro/sistema). Disponible también para invitados
| (selector visible en el login), de ahí que no lleve middleware "auth".
*/
Route::post('theme', [ThemeController::class, 'update'])->name('theme.update');

/*
| Redirección post-login según el rol.
*/
Route::get('home', function () {
    return redirect(Auth::user()->isDriver() ? route('chofer.today') : route('dashboard'));
})->middleware('auth')->name('home');

/*
|--------------------------------------------------------------------------
| Gestión (Administrador + Mantenimiento)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:administrador|mantenimiento'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::view('style-guide', 'style-guide')->name('style-guide');

    Route::get('chofers', DriversIndex::class)->name('drivers.index');
    Route::get('camiones', TrucksIndex::class)->name('trucks.index');
    Route::get('usuarios', UsersIndex::class)->name('users.index');

    // El tablero es la vista principal de "Rutas"; la ficha CRUD clásica queda en /rutas/listado.
    Route::get('rutas', RoutesBoard::class)->name('routes.board');
    Route::get('rutas/listado', RoutesIndex::class)->name('routes.index');
});

/*
|--------------------------------------------------------------------------
| Panel exclusivo de Mantenimiento (logs y auditoría)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:mantenimiento'])->prefix('mantenimiento')->name('maintenance.')->group(function () {
    // Panel de logs — Bloque 6.
});

/*
|--------------------------------------------------------------------------
| Operativa del Chofer
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:chofer'])->prefix('chofer')->name('chofer.')->group(function () {
    Route::view('ruta', 'chofer.placeholder')->name('today');
    // Operativa completa — Bloque 7.
});

/*
| Perfil (todos los usuarios autenticados)
*/
Route::view('profile', 'profile')->middleware('auth')->name('profile');

require __DIR__.'/auth.php';
