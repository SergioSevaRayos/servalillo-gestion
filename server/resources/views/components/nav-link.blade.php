@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-primary-700 bg-primary-500/10 dark:text-primary-300 dark:bg-primary-400/10 transition duration-150 ease-out'
            : 'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-900/5 dark:text-slate-400 dark:hover:text-white dark:hover:bg-white/10 transition duration-150 ease-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
