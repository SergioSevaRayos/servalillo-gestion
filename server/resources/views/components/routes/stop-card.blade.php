@props(['stop'])

@php $draggable = $stop->status === \App\Enums\RouteStopStatus::Pending; @endphp

<li
    data-stop-id="{{ $stop->id }}"
    data-draggable="{{ $draggable ? 'true' : 'false' }}"
    wire:key="stop-{{ $stop->id }}"
    wire:click="openEditStop({{ $stop->id }})"
    @class([
        // transition-shadow, no "transition" a secas: Tailwind anima "transform" por defecto,
        // y eso pelea con la animación de reordenación (FLIP) que aplica SortableJS al arrastrar.
        'select-none rounded-xl p-3 transition-shadow',
        'surface cursor-grab hover:shadow-soft-md active:cursor-grabbing' => $draggable,
        'cursor-pointer border border-slate-200/60 bg-slate-100/60 grayscale-[0.3] hover:bg-slate-100 dark:border-white/5 dark:bg-white/[0.03] dark:hover:bg-white/[0.05]' => ! $draggable,
    ])
>
    <div class="flex items-start justify-between gap-2">
        <p @class([
            'text-sm font-medium',
            'text-slate-800 dark:text-slate-100' => $draggable,
            'text-slate-500 dark:text-slate-400' => ! $draggable,
        ])>{{ $stop->customer_name }}</p>
        <x-ui.badge :variant="$stop->status->badgeVariant()" class="shrink-0">{{ $stop->status->label() }}</x-ui.badge>
    </div>

    @if ($stop->address)
        <p class="mt-1 truncate text-xs text-slate-400">{{ $stop->address }}</p>
    @endif

    <div class="mt-2 flex flex-wrap items-center gap-2">
        @if ($stop->deliveryType)
            <x-ui.badge variant="primary">{{ $stop->deliveryType->name }}</x-ui.badge>
        @endif
        @if ($stop->planned_quantity)
            <span class="text-xs text-slate-500 dark:text-slate-400">{{ number_format($stop->planned_quantity, 0, ',', '.') }}</span>
        @endif
        <x-stop-dwell :stop="$stop" variant="badge" />
    </div>
</li>
