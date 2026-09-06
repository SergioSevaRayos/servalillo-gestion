@props(['label', 'value', 'trend' => null, 'trendDirection' => 'up'])

<div {{ $attributes->merge(['class' => 'glass rounded-2xl p-5 flex flex-col gap-3']) }}>
    <div class="flex items-center justify-between">
        <span class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $label }}</span>
        @isset($icon)
            <span class="h-9 w-9 shrink-0 rounded-full bg-primary-500/10 text-primary-600 dark:text-primary-400 grid place-items-center">
                {{ $icon }}
            </span>
        @endisset
    </div>

    <div class="flex items-baseline gap-2">
        <span class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $value }}</span>

        @if ($trend)
            <span @class([
                'text-xs font-medium rounded-full px-1.5 py-0.5',
                'text-emerald-700 bg-emerald-100 dark:text-emerald-300 dark:bg-emerald-500/10' => $trendDirection === 'up',
                'text-rose-700 bg-rose-100 dark:text-rose-300 dark:bg-rose-500/10' => $trendDirection === 'down',
            ])>{{ $trend }}</span>
        @endif
    </div>
</div>
