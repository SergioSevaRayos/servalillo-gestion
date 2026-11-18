@props(['active' => false])

{{-- Enlace del menú lateral (escritorio). Mismos tokens que <x-nav-link>, pero a todo el ancho y
     con hueco a la derecha para una insignia (facturas pendientes, avisos activos). --}}
@php
$classes = $active
    ? 'bg-primary-500/10 font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-300'
    : 'font-medium text-slate-600 hover:bg-slate-900/5 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white';
@endphp

<a {{ $attributes->merge(['class' => "flex w-full items-center justify-between gap-2 rounded-lg px-3 py-1 text-sm transition-colors duration-150 {$classes}"]) }}
    @if ($active) aria-current="page" @endif>
    {{ $slot }}
</a>
