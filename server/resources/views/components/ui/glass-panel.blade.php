{{-- "Chrome" flotante: nav, modales, KPIs, toasts, selector de tema. Nunca en tablas/formularios. --}}
@props(['padded' => true])

<div {{ $attributes->merge(['class' => 'glass rounded-2xl ' . ($padded ? 'p-5' : '')]) }}>
    {{ $slot }}
</div>
