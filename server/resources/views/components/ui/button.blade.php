@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
$sizes = [
    'sm' => 'px-3 py-1.5 text-sm gap-1.5',
    'md' => 'px-4 py-2 text-sm gap-2',
    'lg' => 'px-5 py-2.5 text-base gap-2',
];

$variants = [
    'primary' => 'bg-primary-600 text-white shadow-soft hover:bg-primary-700 active:bg-primary-800 disabled:bg-primary-300',
    'secondary' => 'bg-white text-slate-700 border border-slate-200 shadow-soft-sm hover:bg-slate-50 active:bg-slate-100 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700 dark:hover:bg-slate-700',
    'ghost' => 'text-slate-600 hover:bg-slate-900/5 dark:text-slate-300 dark:hover:bg-white/10',
    'danger' => 'bg-rose-600 text-white shadow-soft hover:bg-rose-700 active:bg-rose-800',
];

$classes = 'inline-flex items-center justify-center rounded-lg font-medium transition duration-150 ease-out disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950 '
    . ($sizes[$size] ?? $sizes['md']) . ' '
    . ($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
