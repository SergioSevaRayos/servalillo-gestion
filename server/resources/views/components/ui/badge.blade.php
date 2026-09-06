@props(['variant' => 'neutral'])

@php
$variants = [
    'neutral' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    'primary' => 'bg-primary-500/10 text-primary-700 dark:text-primary-300',
    'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    'danger' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ' . ($variants[$variant] ?? $variants['neutral'])]) }}>
    {{ $slot }}
</span>
