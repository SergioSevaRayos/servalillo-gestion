<x-app-layout>
    <x-slot name="header">{{ __('Panel') }}</x-slot>

    <div class="space-y-8">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="{{ __('Rutas activas hoy') }}" :value="\App\Models\Route::whereDate('route_date', today())->operational()->count()" />
            <x-ui.stat-card label="{{ __('Entregas completadas hoy') }}" :value="\App\Models\RouteStop::whereDate('completed_at', today())->where('status', 'completed')->count()" />
            <x-ui.stat-card label="{{ __('Camiones en flota') }}" :value="\App\Models\Truck::where('is_active', true)->count()" />
            <x-ui.stat-card label="{{ __('Chofers activos') }}" :value="\App\Models\Driver::where('is_active', true)->count()" />
        </div>

        <x-ui.card>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ __('Bienvenido/a, :name. Los listados de chofers, camiones y rutas llegan en el Bloque 3.', ['name' => auth()->user()->name]) }}
            </p>
        </x-ui.card>
    </div>
</x-app-layout>
