@props(['label', 'value', 'trend' => null, 'trendDirection' => 'up'])

@php
    // Tamaño del valor según su longitud: un texto corto ("128", "34 min") luce grande,
    // pero esa misma clase fija con un valor largo (p.ej. una fecha "28/04/2026" en una
    // rejilla de 5 columnas) se salía del borde de la tarjeta — se encoge en vez de
    // desbordar. Umbrales calibrados contra los valores reales que ya usan las tarjetas
    // (KPIs cortos, litros formateados, fechas dd/mm/yyyy).
    $valueLength = mb_strlen((string) $value);
    $valueSize = match (true) {
        $valueLength > 9 => 'text-xl',
        $valueLength > 6 => 'text-2xl',
        default => 'text-3xl',
    };
@endphp

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
        <span class="{{ $valueSize }} font-semibold tracking-tight text-slate-900 dark:text-white">{{ $value }}</span>

        @if ($trend)
            <span @class([
                'text-xs font-medium rounded-full px-1.5 py-0.5',
                'text-emerald-700 bg-emerald-100 dark:text-emerald-300 dark:bg-emerald-500/10' => $trendDirection === 'up',
                'text-rose-700 bg-rose-100 dark:text-rose-300 dark:bg-rose-500/10' => $trendDirection === 'down',
            ])>{{ $trend }}</span>
        @endif
    </div>
</div>
