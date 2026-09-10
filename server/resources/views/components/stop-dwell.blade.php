@props(['stop', 'variant' => 'badge'])

@php
    use App\Support\Duration;

    $onSite = $stop->onSiteSeconds();
    $here = $stop->isOnSiteNow();
    $arrival = $stop->firstArrivalAt();
    $departure = $stop->lastDepartureAt();
@endphp

@if ($onSite !== null || $here)
    @if ($variant === 'line')
        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            @if ($arrival)
                <span>{{ __('Llegada') }} {{ $arrival->format('H:i') }}</span>
            @endif
            @if ($here)
                <span class="font-medium text-amber-600 dark:text-amber-400">· {{ __('en parada ahora') }} ({{ Duration::humanShort($onSite ?? 0) }})</span>
            @else
                @if ($departure)
                    <span>· {{ __('Salida') }} {{ $departure->format('H:i') }}</span>
                @endif
                <span class="font-medium text-slate-600 dark:text-slate-300">· {{ Duration::humanShort($onSite) }} {{ __('en parada') }}</span>
            @endif
        </p>
    @else
        <span @class([
            'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium',
            'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $here,
            'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300' => ! $here,
        ])>
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            @if ($here)
                {{ __('En parada') }} {{ Duration::humanShort($onSite ?? 0) }}
            @else
                {{ Duration::humanShort($onSite) }}
            @endif
        </span>
    @endif
@endif
