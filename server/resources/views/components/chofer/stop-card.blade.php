@props(['stop', 'index', 'disabled' => false])

@php
    $closed = $stop->status !== \App\Enums\RouteStopStatus::Pending;
@endphp

<button
    type="button"
    @disabled($disabled)
    wire:click="openStop({{ $stop->id }})"
    wire:key="stop-{{ $stop->id }}"
    @class([
        'surface w-full rounded-xl p-4 text-left transition-shadow',
        'hover:shadow-soft-md active:shadow-soft-sm' => ! $disabled,
        'opacity-60' => $disabled,
        'ring-1 ring-emerald-500/30' => $stop->status === \App\Enums\RouteStopStatus::Completed,
    ])
>
    <div class="flex items-start gap-3">
        <span @class([
            'mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full text-sm font-semibold',
            'bg-primary-500/10 text-primary-700 dark:text-primary-300' => ! $closed,
            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $stop->status === \App\Enums\RouteStopStatus::Completed,
            'bg-rose-500/10 text-rose-700 dark:text-rose-300' => $stop->status === \App\Enums\RouteStopStatus::Failed,
            'bg-slate-500/10 text-slate-600 dark:text-slate-300' => $stop->status === \App\Enums\RouteStopStatus::Skipped,
        ])>{{ $index }}</span>

        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <p class="font-semibold text-slate-900 dark:text-white">{{ $stop->customer_name }}</p>
                <x-ui.badge :variant="$stop->status->badgeVariant()" class="shrink-0">{{ $stop->status->label() }}</x-ui.badge>
            </div>

            @if ($stop->address)
                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $stop->address }}</p>
            @endif

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                @if ($stop->deliveryType)
                    <span class="font-medium text-primary-700 dark:text-primary-300">{{ $stop->deliveryType->name }}</span>
                @endif
                @if ($stop->status === \App\Enums\RouteStopStatus::Completed && $stop->delivered_quantity !== null)
                    <span class="text-emerald-700 dark:text-emerald-400">{{ number_format($stop->delivered_quantity, 0, ',', '.') }} L entregados</span>
                @elseif ($stop->planned_quantity)
                    <span class="text-slate-500 dark:text-slate-400">{{ number_format($stop->planned_quantity, 0, ',', '.') }} L previstos</span>
                @endif
            </div>

            @if ($stop->failure_reason)
                <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $stop->failure_reason }}</p>
            @endif

            <x-stop-dwell :stop="$stop" variant="line" />
        </div>
    </div>
</button>
