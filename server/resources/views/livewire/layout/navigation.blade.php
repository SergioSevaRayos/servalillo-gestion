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

<nav class="sticky top-0 z-30 px-4 pt-4">
    {{-- Pill flotante (padding + esquinas redondeadas). En móvil se hace OPACO
         (`max-md:bg-white` en vez de `bg-white/70`): así el contenido no translucía por detrás al
         hacer scroll (parecía que la barra se movía). El `backdrop-filter` se anula en móvil desde
         app.css (`nav.sticky > .glass`): aunque no se vea, "vibra" al repintarse cada frame. --}}
    <div class="glass mx-auto flex h-16 max-w-7xl items-center justify-between rounded-2xl px-4 max-md:bg-white dark:max-md:bg-slate-900 sm:px-6">
        {{-- min-w-0: un hijo flex no encoge por debajo del ancho de su contenido si no se le
             fuerza — sin esto, con muchos enlaces (Depósitos sumó uno más) esta columna nunca cede
             sitio y "empuja" el cluster derecho (campana/tema/usuario), pisándolo en vez de dejarle
             su hueco. La lista de enlaces hace scroll horizontal propio en vez de encogerse (mismo
             criterio que el resto de la app: nunca recortar contenido, dejarlo desplazable). --}}
        <div class="flex min-w-0 flex-1 items-center gap-8">
            {{-- El nombre "Gestión Servalillo" se quitó de aquí (2026-09-12): con "Fichar"/
                 "Fichajes" sumados a la nav, ese texto era lo que forzaba el scroll horizontal de
                 la lista de enlaces en escritorio — quitarlo (y dejar solo el icono) es lo que de
                 verdad libera sitio, no maquillarlo. `title` en el `<a>` mantiene el nombre
                 accesible al pasar el ratón. --}}
            <a href="{{ route('home') }}" wire:navigate title="{{ config('app.name') }}" class="flex shrink-0 items-center text-primary-700 dark:text-primary-300">
                <x-application-logo class="h-7 w-7" />
            </a>

            <div class="themed-scrollbar hidden min-w-0 items-center gap-1 overflow-x-auto md:flex">
                @auth
                    @if (auth()->user()->isManager())
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Panel') }}
                        </x-nav-link>
                        @can('viewAny', \App\Models\Route::class)
                            <x-nav-link :href="route('routes.board')" :active="request()->routeIs('routes.*')" wire:navigate>
                                {{ __('Rutas') }}
                            </x-nav-link>
                        @endcan
                        @can('viewAny', \App\Models\Client::class)
                            <x-nav-link :href="route('clients.index')" :active="request()->routeIs('clients.*')" wire:navigate>
                                {{ __('Clientes') }}
                            </x-nav-link>
                        @endcan
                        @can('viewAny', \App\Models\Driver::class)
                            <x-nav-link :href="route('drivers.index')" :active="request()->routeIs('drivers.index')" wire:navigate>
                                {{ __('Chofers') }}
                            </x-nav-link>
                        @endcan
                        @can('viewAny', \App\Models\Truck::class)
                            <x-nav-link :href="route('trucks.index')" :active="request()->routeIs('trucks.index')" wire:navigate>
                                {{ __('Camiones') }}
                            </x-nav-link>
                        @endcan
                        @can('viewAny', \App\Models\DeliveryNote::class)
                            <x-nav-link :href="route('delivery-notes.index')" :active="request()->routeIs('delivery-notes.*')" wire:navigate>
                                {{ __('Albaranes') }}
                            </x-nav-link>
                        @endcan
                        @can('sgra.view')
                            <x-nav-link :href="route('sgra.index')" :active="request()->routeIs('sgra.index')" wire:navigate>
                                {{ __('Depósitos') }}
                            </x-nav-link>
                        @endcan
                        @if (config('servalillo.attendance.enabled'))
                            @can('attendance.manage')
                                <x-nav-link :href="route('attendance.manage')" :active="request()->routeIs('attendance.manage')" wire:navigate>
                                    {{ __('Fichajes') }}
                                </x-nav-link>
                            @endcan
                        @endif
                        @can('viewAny', \App\Models\User::class)
                            <x-nav-link :href="route('users.index')" :active="request()->routeIs('users.index')" wire:navigate>
                                {{ __('Usuarios') }}
                            </x-nav-link>
                        @endcan
                        @if (auth()->user()->isMaintenance())
                            <x-nav-link :href="route('maintenance.index')" :active="request()->routeIs('maintenance.*')" wire:navigate>
                                {{ __('Mantenimiento') }}
                            </x-nav-link>
                        @endif
                        @if (config('servalillo.attendance.enabled') && auth()->user()->hasRole('administrador'))
                            <x-nav-link :href="route('attendance.index')" :active="request()->routeIs('attendance.index')" wire:navigate>
                                {{ __('Fichar') }}
                            </x-nav-link>
                        @endif
                    @else
                        <x-nav-link :href="route('chofer.today')" :active="request()->routeIs('chofer.*')" wire:navigate>
                            {{ __('Mi ruta') }}
                        </x-nav-link>
                        @if (config('servalillo.attendance.enabled'))
                            <x-nav-link :href="route('attendance.index')" :active="request()->routeIs('attendance.index')" wire:navigate>
                                {{ __('Fichar') }}
                            </x-nav-link>
                        @endif
                    @endif
                @endauth
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-3">
            @auth
                {{-- La campana la ve todo el mundo: el chofer recibe avisos cuando oficina le
                     gestiona una parada de la ruta (Bloque 14). --}}
                <livewire:notifications.bell />
                @if (auth()->user()->hasRole('administrador'))
                    <a href="{{ route('support.index') }}" wire:navigate
                        title="{{ __('Soporte / incidencias') }}" aria-label="{{ __('Soporte / incidencias') }}"
                        @class([
                            'grid h-9 w-9 place-items-center rounded-full transition',
                            'bg-primary-500/10 text-primary-700 dark:text-primary-300' => request()->routeIs('support.*'),
                            'text-slate-600 hover:bg-slate-900/5 dark:text-slate-300 dark:hover:bg-white/10' => ! request()->routeIs('support.*'),
                        ])>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M21.75 6.75a4.5 4.5 0 0 1-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 1 1-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 0 1 6.336-4.486l-3.276 3.276a3.004 3.004 0 0 0 2.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852Z" />
                        </svg>
                    </a>
                @endif
            @endauth

            <div class="hidden md:block">
                <x-dropdown align="right" width="52">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-2 rounded-full py-1 pl-1 pr-3 text-sm font-medium text-slate-600 transition hover:bg-slate-900/5 dark:text-slate-300 dark:hover:bg-white/10">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-primary-500/10 text-sm font-semibold text-primary-700 dark:text-primary-300">
                                {{ Str::of(auth()->user()->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                            </span>
                            <span x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        {{-- Tema movido aquí desde la barra (2026-09-11): con "Depósitos" ya eran
                             8 enlaces y el selector de tema en la nav no dejaba sitio sin hacer
                             scroll horizontal — quitarlo de ahí es lo que de verdad gana espacio,
                             no solo maquillarlo. --}}
                        <div class="flex items-center justify-between gap-3 px-4 py-2">
                            <span class="text-sm text-slate-600 dark:text-slate-300">{{ __('Tema') }}</span>
                            <x-ui.theme-toggle />
                        </div>
                        <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
                        <x-dropdown-link :href="route('profile')" wire:navigate>{{ __('Mi perfil') }}</x-dropdown-link>
                        @if (auth()->user()->isManager())
                            <x-dropdown-link :href="route('style-guide')" wire:navigate>{{ __('Guía de estilo') }}</x-dropdown-link>
                        @endif
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>{{ __('Cerrar sesión') }}</x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>

    {{-- Menú móvil: botón gota fijo abajo-derecha. Se teletransporta a <body> para no quedar
         anidado en el <nav> sticky (iOS Safari coloca mal un position:fixed dentro de sticky). --}}
    <template x-teleport="body"><x-ui.drop-menu>
        @auth
            @if (auth()->user()->isManager())
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Panel') }}
                </x-responsive-nav-link>
                @can('viewAny', \App\Models\Route::class)
                    <x-responsive-nav-link :href="route('routes.board')" :active="request()->routeIs('routes.*')" wire:navigate>
                        {{ __('Rutas') }}
                    </x-responsive-nav-link>
                @endcan
                @can('viewAny', \App\Models\Client::class)
                    <x-responsive-nav-link :href="route('clients.index')" :active="request()->routeIs('clients.*')" wire:navigate>
                        {{ __('Clientes') }}
                    </x-responsive-nav-link>
                @endcan
                @can('viewAny', \App\Models\Driver::class)
                    <x-responsive-nav-link :href="route('drivers.index')" :active="request()->routeIs('drivers.index')" wire:navigate>
                        {{ __('Chofers') }}
                    </x-responsive-nav-link>
                @endcan
                @can('viewAny', \App\Models\Truck::class)
                    <x-responsive-nav-link :href="route('trucks.index')" :active="request()->routeIs('trucks.index')" wire:navigate>
                        {{ __('Camiones') }}
                    </x-responsive-nav-link>
                @endcan
                @can('viewAny', \App\Models\DeliveryNote::class)
                    <x-responsive-nav-link :href="route('delivery-notes.index')" :active="request()->routeIs('delivery-notes.*')" wire:navigate>
                        {{ __('Albaranes') }}
                    </x-responsive-nav-link>
                @endcan
                @can('sgra.view')
                    <x-responsive-nav-link :href="route('sgra.index')" :active="request()->routeIs('sgra.index')" wire:navigate>
                        {{ __('Depósitos') }}
                    </x-responsive-nav-link>
                @endcan
                @if (config('servalillo.attendance.enabled'))
                    @can('attendance.manage')
                        <x-responsive-nav-link :href="route('attendance.manage')" :active="request()->routeIs('attendance.manage')" wire:navigate>
                            {{ __('Fichajes') }}
                        </x-responsive-nav-link>
                    @endcan
                @endif
                @can('viewAny', \App\Models\User::class)
                    <x-responsive-nav-link :href="route('users.index')" :active="request()->routeIs('users.index')" wire:navigate>
                        {{ __('Usuarios') }}
                    </x-responsive-nav-link>
                @endcan
                @if (auth()->user()->isMaintenance())
                    <x-responsive-nav-link :href="route('maintenance.index')" :active="request()->routeIs('maintenance.*')" wire:navigate>
                        {{ __('Mantenimiento') }}
                    </x-responsive-nav-link>
                @endif
                @if (auth()->user()->hasRole('administrador'))
                    <x-responsive-nav-link :href="route('support.index')" :active="request()->routeIs('support.*')" wire:navigate>
                        {{ __('Soporte / incidencias') }}
                    </x-responsive-nav-link>
                @endif
                @if (config('servalillo.attendance.enabled') && auth()->user()->hasRole('administrador'))
                    <x-responsive-nav-link :href="route('attendance.index')" :active="request()->routeIs('attendance.index')" wire:navigate>
                        {{ __('Fichar') }}
                    </x-responsive-nav-link>
                @endif
                <x-responsive-nav-link :href="route('style-guide')" :active="request()->routeIs('style-guide')" wire:navigate>
                    {{ __('Guía de estilo') }}
                </x-responsive-nav-link>
            @else
                <x-responsive-nav-link :href="route('chofer.today')" :active="request()->routeIs('chofer.*')" wire:navigate>
                    {{ __('Mi ruta') }}
                </x-responsive-nav-link>
                @if (config('servalillo.attendance.enabled'))
                    <x-responsive-nav-link :href="route('attendance.index')" :active="request()->routeIs('attendance.index')" wire:navigate>
                        {{ __('Fichar') }}
                    </x-responsive-nav-link>
                @endif
            @endif

            <x-responsive-nav-link :href="route('profile')" wire:navigate>{{ __('Mi perfil') }}</x-responsive-nav-link>

            <div class="my-1 border-t border-slate-900/10 dark:border-white/10"></div>

            <div class="px-3 py-2">
                <x-ui.theme-toggle />
            </div>

            <button wire:click="logout" class="w-full text-start">
                <x-responsive-nav-link>{{ __('Cerrar sesión') }}</x-responsive-nav-link>
            </button>
        @endauth
    </x-ui.drop-menu></template>
</nav>
