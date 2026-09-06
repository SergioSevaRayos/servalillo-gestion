@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full rounded-lg px-3 py-2 text-start text-base font-medium text-primary-700 bg-primary-500/10 dark:text-primary-300 dark:bg-primary-400/10 transition duration-150 ease-out'
            : 'block w-full rounded-lg px-3 py-2 text-start text-base font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-900/5 dark:text-slate-300 dark:hover:text-white dark:hover:bg-white/10 transition duration-150 ease-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
