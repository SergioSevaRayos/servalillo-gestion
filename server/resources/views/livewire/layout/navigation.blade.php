<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

@php
    $user = auth()->user();

    // Una sola definición de enlaces para el menú lateral (escritorio) y el menú móvil: antes
    // estaban escritos dos veces y cada enlace nuevo había que acordarse de ponerlo en ambos.
    // Cada grupo: [título|null, [[etiqueta, ruta, patrones de "activo", insignia|null, color insignia]]].
    $groups = [];

    if ($user->isManager()) {
        $groups[] = [null, array_filter([
            [__('Panel'), 'dashboard', ['dashboard']],
        ])];

        $groups[] = [__('Operación'), array_filter([
            $user->can('viewAny', \App\Models\Route::class) ? [__('Rutas'), 'routes.board', ['routes.*']] : null,
            $user->can('devices.manage') ? [__('Mapa'), 'fleet.map', ['fleet.map']] : null,
            $user->can('viewAny', \App\Models\DeliveryNote::class) ? [__('Albaranes'), 'delivery-notes.index', ['delivery-notes.*']] : null,
            $user->can('invoices.manage') ? [__('Facturas'), 'invoices.index', ['invoices.*'], \App\Models\Invoice::query()->pending()->count(), 'bg-amber-500'] : null,
            $user->can('alerts.manage') ? [__('Avisos'), 'alerts.index', ['alerts.index'], \App\Models\AlertEvent::query()->open()->count(), 'bg-rose-500'] : null,
        ])];

        $groups[] = [__('Datos'), array_filter([
            $user->can('viewAny', \App\Models\Client::class) ? [__('Clientes'), 'clients.index', ['clients.*']] : null,
            $user->can('viewAny', \App\Models\Driver::class) ? [__('Chofers'), 'drivers.index', ['drivers.index']] : null,
            $user->can('viewAny', \App\Models\Truck::class) ? [__('Camiones'), 'trucks.index', ['trucks.index']] : null,
            $user->can('sgra.view') ? [__('Depósitos'), 'sgra.index', ['sgra.index']] : null,
            $user->can('viewAny', \App\Models\User::class) ? [__('Usuarios'), 'users.index', ['users.index']] : null,
        ])];

        $groups[] = [__('Personal'), array_filter([
            $user->attendanceVisible() && $user->can('attendance.manage') ? [__('Fichajes'), 'attendance.manage', ['attendance.manage', 'attendance.totals']] : null,
            $user->attendanceVisible() && $user->canPunchAttendance() ? [__('Fichar'), 'attendance.index', ['attendance.index']] : null,
        ])];

        $groups[] = [__('Sistema'), array_filter([
            $user->isMaintenance() ? [__('Mantenimiento'), 'maintenance.index', ['maintenance.*']] : null,
            $user->hasRole('administrador') ? [__('Soporte / incidencias'), 'support.index', ['support.*']] : null,
        ])];
    } else {
        $groups[] = [null, array_filter([
            [__('Mi ruta'), 'chofer.today', ['chofer.*']],
            $user->attendanceVisible() && $user->canPunchAttendance() ? [__('Fichar'), 'attendance.index', ['attendance.index']] : null,
        ])];
    }

    // Cada enlace se normaliza a 5 campos (insignia y color opcionales) y se descartan los grupos vacíos.
    $groups = array_values(array_filter(array_map(
        fn ($g) => [$g[0], array_map(fn ($i) => array_pad($i, 5, null), array_values($g[1]))],
        $groups,
    ), fn ($g) => count($g[1]) > 0));
    $initials = Str::of($user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
@endphp

{{-- Raíz ÚNICA (Livewire). En móvil es la barra superior pegajosa de siempre; en escritorio
     (md+) deja de ocupar sitio: el menú lateral es un hijo `fixed`. NO envolver esto en un
     <div> ni usar `display: contents` (rompen el sticky móvil, ver CLAUDE.md). --}}
<nav class="sticky top-0 z-30 px-4 pt-4 md:static md:p-0">

    {{-- ============ Móvil: pill flotante OPACA con logo + campana (el menú es la gota FAB) ============ --}}
    <div class="glass mx-auto flex h-16 max-w-7xl items-center justify-between rounded-2xl bg-white px-4 dark:bg-slate-900 sm:px-6 md:hidden">
        <a href="{{ route('home') }}" wire:navigate title="{{ config('app.name') }}" class="flex shrink-0 items-center">
            <img src="{{ asset('images/logo-aguas-del-servalillo.png') }}" alt="Aguas del Servalillo" class="h-9 w-auto dark:hidden" width="480" height="145">
<img src="{{ asset('images/logo-aguas-del-servalillo-oscuro.png') }}" alt="" aria-hidden="true" class="h-9 w-auto hidden dark:block" width="492" height="157">
        </a>
        <div class="flex items-center gap-3">
            <livewire:notifications.bell />
        </div>
    </div>

    {{-- ============ Escritorio: menú lateral fijo a la izquierda ============ --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-60 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 md:flex">
        {{-- Cabecera: logo + campana. Fuera del bloque con scroll para que el desplegable de la
             campana no se recorte contra su overflow. --}}
        <div class="flex h-14 shrink-0 items-center justify-between gap-2 px-4">
            <a href="{{ route('home') }}" wire:navigate title="{{ config('app.name') }}" class="flex min-w-0 items-center">
                <img src="{{ asset('images/logo-aguas-del-servalillo.png') }}" alt="Aguas del Servalillo" class="h-9 w-auto max-w-full dark:hidden" width="480" height="145">
<img src="{{ asset('images/logo-aguas-del-servalillo-oscuro.png') }}" alt="" aria-hidden="true" class="h-9 w-auto max-w-full hidden dark:block" width="492" height="157">
            </a>
            <livewire:notifications.bell />
        </div>

        {{-- Enlaces: scroll VERTICAL propio si no caben (portátil bajo); nunca horizontal. --}}
        <div class="themed-scrollbar min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-1">
            @foreach ($groups as [$title, $items])
                <div>
                    @if ($title)
                        <p class="mb-0.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ $title }}</p>
                    @endif
                    <div class="space-y-0.5">
                        @foreach ($items as [$label, $routeName, $patterns, $badge, $badgeColor])
                            <x-side-link :href="route($routeName)" :active="request()->routeIs(...$patterns)" wire:navigate>
                                <span>{{ $label }}</span>
                                @if (($badge ?? 0) > 0)
                                    <span class="rounded-full {{ $badgeColor }} px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">{{ $badge }}</span>
                                @endif
                            </x-side-link>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pie: usuario + menú que se abre HACIA ARRIBA (tema, perfil, guía, cerrar sesión).
             <x-dropdown> solo abre hacia abajo, y aquí no cabría. --}}
        <div class="relative shrink-0 border-t border-slate-200 p-2 dark:border-slate-800" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
            <div x-show="open" x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="glass absolute inset-x-3 bottom-full z-50 mb-2 rounded-xl bg-white py-1.5 dark:bg-slate-900"
                style="display: none;"
                @click="open = false">
                <div class="flex items-center justify-between gap-3 px-4 py-2" @click.stop>
                    <span class="text-sm text-slate-600 dark:text-slate-300">{{ __('Tema') }}</span>
                    <x-ui.theme-toggle />
                </div>
                <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
                <x-dropdown-link :href="route('profile')" wire:navigate>{{ __('Mi perfil') }}</x-dropdown-link>
                @if ($user->isManager())
                    <x-dropdown-link :href="route('style-guide')" wire:navigate>{{ __('Guía de estilo') }}</x-dropdown-link>
                @endif
                <button wire:click="logout" class="w-full text-start">
                    <x-dropdown-link>{{ __('Cerrar sesión') }}</x-dropdown-link>
                </button>
            </div>

            <button type="button" @click="open = ! open" :aria-expanded="open"
                class="flex w-full items-center gap-2.5 rounded-lg p-1.5 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-900/5 dark:text-slate-200 dark:hover:bg-white/10">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary-500/10 text-sm font-semibold text-primary-700 dark:text-primary-300">{{ $initials }}</span>
                <span class="min-w-0 flex-1 truncate" x-data="{{ json_encode(['name' => $user->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 0 1-1.06-.02L10 8.832 6.29 12.77a.75.75 0 1 1-1.08-1.04l4.25-4.5a.75.75 0 0 1 1.08 0l4.25 4.5a.75.75 0 0 1-.02 1.06Z" clip-rule="evenodd" /></svg>
            </button>
        </div>
    </aside>

    {{-- Menú móvil: botón gota fijo abajo-derecha. Se teletransporta a <body> para no quedar
         anidado en el <nav> sticky (iOS Safari coloca mal un position:fixed dentro de sticky). --}}
    <template x-teleport="body"><x-ui.drop-menu>
        @foreach ($groups as [$title, $items])
            @foreach ($items as [$label, $routeName, $patterns, $badge, $badgeColor])
                <x-responsive-nav-link :href="route($routeName)" :active="request()->routeIs(...$patterns)" wire:navigate>
                    {{ $label }}
                </x-responsive-nav-link>
            @endforeach
        @endforeach

        @if ($user->isManager())
            <x-responsive-nav-link :href="route('style-guide')" :active="request()->routeIs('style-guide')" wire:navigate>
                {{ __('Guía de estilo') }}
            </x-responsive-nav-link>
        @endif

        <x-responsive-nav-link :href="route('profile')" wire:navigate>{{ __('Mi perfil') }}</x-responsive-nav-link>

        <div class="my-1 border-t border-slate-900/10 dark:border-white/10"></div>

        <div class="px-3 py-2">
            <x-ui.theme-toggle />
        </div>

        <button wire:click="logout" class="w-full text-start">
            <x-responsive-nav-link>{{ __('Cerrar sesión') }}</x-responsive-nav-link>
        </button>
    </x-ui.drop-menu></template>
</nav>
