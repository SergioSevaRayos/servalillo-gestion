@php
    $tabs = [
        'maintenance.index' => __('Resumen'),
        'maintenance.audits' => __('Auditoría'),
        'maintenance.errors' => __('Errores del sistema'),
        'maintenance.logs' => __('Log de la aplicación'),
        'maintenance.support' => __('Soporte'),
        'maintenance.devices' => __('Dispositivos'),
    ];
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Mantenimiento') }}</h1>

    <nav class="mt-3 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-700">
        @foreach ($tabs as $route => $label)
            <a
                href="{{ route($route) }}"
                wire:navigate
                @class([
                    'shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-primary-600 text-primary-700 dark:text-primary-300' => request()->routeIs($route),
                    'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => ! request()->routeIs($route),
                ])
            >{{ $label }}</a>
        @endforeach
    </nav>
</div>
