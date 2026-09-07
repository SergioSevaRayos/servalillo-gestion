<?php

use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\ThemeController;
use App\Livewire\Chofer\Today;
use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Clients\Show as ClientsShow;
use App\Livewire\Dashboard\Index as DashboardIndex;
use App\Livewire\DeliveryNotes\Index as DeliveryNotesIndex;
use App\Livewire\Drivers\Index as DriversIndex;
use App\Livewire\Maintenance\Audits as MaintenanceAudits;
use App\Livewire\Maintenance\Errors as MaintenanceErrors;
use App\Livewire\Maintenance\SystemLog as MaintenanceSystemLog;
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
    // Panel estadístico (Bloque 5). El permiso `stats.view` lo tiene administrador;
    // mantenimiento pasa por el Gate::before de superusuario técnico.
    Route::get('dashboard', DashboardIndex::class)->middleware('permission:stats.view')->name('dashboard');
    Route::view('style-guide', 'style-guide')->name('style-guide');

    Route::get('clientes', ClientsIndex::class)->middleware('permission:clients.view')->name('clients.index');
    Route::get('clientes/{client}', ClientsShow::class)->middleware('permission:clients.view')->name('clients.show');

    Route::get('chofers', DriversIndex::class)->name('drivers.index');
    Route::get('camiones', TrucksIndex::class)->name('trucks.index');
    Route::get('usuarios', UsersIndex::class)->name('users.index');

    // El tablero es la vista principal de "Rutas"; la ficha CRUD clásica queda en /rutas/listado.
    Route::get('rutas', RoutesBoard::class)->name('routes.board');
    Route::get('rutas/listado', RoutesIndex::class)->name('routes.index');

    Route::get('albaranes', DeliveryNotesIndex::class)->middleware('permission:delivery_notes.view')->name('delivery-notes.index');
});

/*
| Descarga del PDF de un albarán: manager o el chofer dueño de la parada (lo decide la Policy).
*/
Route::get('albaranes/{note}/pdf', [DeliveryNoteController::class, 'pdf'])
    ->middleware('auth')->name('delivery-notes.pdf');

/*
|--------------------------------------------------------------------------
| Panel exclusivo de Mantenimiento (logs y auditoría)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:mantenimiento'])->prefix('mantenimiento')->name('maintenance.')->group(function () {
    Route::redirect('/', '/mantenimiento/auditoria')->name('index');
    Route::get('auditoria', MaintenanceAudits::class)->middleware('permission:audits.view')->name('audits');
    Route::get('errores', MaintenanceErrors::class)->middleware('permission:system_logs.view')->name('errors');
    Route::get('log', MaintenanceSystemLog::class)->middleware('permission:system_logs.view')->name('logs');
});

/*
|--------------------------------------------------------------------------
| Operativa del Chofer
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:chofer'])->prefix('chofer')->name('chofer.')->group(function () {
    Route::get('ruta', Today::class)->name('today');
});

/*
| Perfil (todos los usuarios autenticados)
*/
Route::view('profile', 'profile')->middleware('auth')->name('profile');

require __DIR__.'/auth.php';
