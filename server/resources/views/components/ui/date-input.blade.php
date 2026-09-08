@props([
    'label' => null,
    'name',
    'error' => null,
    'help' => null,
    'min' => null,
    'max' => null,
    'placeholder' => 'dd/mm/aaaa',
])

@php
    $wire = $attributes->wire('model');
    $model = $wire->value();
    $live = $wire->hasModifier('live');
    $id = $attributes->get('id', $name);
    $errorMsg = $error ?? ($errors->first($name) ?: null);
@endphp

{{-- Selector de fecha con el estilo del sistema (el calendario nativo del navegador no se puede
     estilar). Lógica en Alpine.data('datePicker'); el panel se teletransporta a <body> para no
     quedar recortado por el overflow de un modal. --}}
<div
    x-data="datePicker({ initial: @js(''), min: @js($min), max: @js($max), model: @js($model) })"
    @if ($model) x-modelable="value" wire:model{{ $live ? '.live' : '' }}="{{ $model }}" @endif
    class="relative"
>
    @if ($label)
        <x-input-label :for="$id" :value="$label" />
    @endif

    <button
        type="button"
        id="{{ $id }}"
        x-ref="trigger"
        x-on:click="toggle()"
        @class([
            'flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left shadow-soft-sm sm:text-sm dark:bg-slate-800 dark:text-slate-100',
            'border-rose-400 focus:border-rose-500 focus:ring-rose-500 dark:border-rose-500/50' => $errorMsg,
            'border-slate-300 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700' => ! $errorMsg,
        ])
    >
        <span x-text="displayValue" x-show="value" class="tabular-nums"></span>
        <span x-show="! value" class="text-slate-400 dark:text-slate-500">{{ $placeholder }}</span>
        <svg class="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0V11.25A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
        </svg>
    </button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-ref="panel"
            :style="panelStyle"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            x-on:keydown.escape.stop="close()"
            class="z-[60] w-[17.5rem] select-none rounded-xl border border-slate-200 bg-white p-3 text-slate-700 shadow-soft-lg dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
        >
            <div class="flex items-center justify-between">
                <button type="button" x-on:click="prevMonth()" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="{{ __('Mes anterior') }}">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 0 1 0 1.414L9.414 10l3.293 3.293a1 1 0 0 1-1.414 1.414l-4-4a1 1 0 0 1 0-1.414l4-4a1 1 0 0 1 1.414 0z" clip-rule="evenodd" /></svg>
                </button>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100" x-text="monthLabel"></span>
                <button type="button" x-on:click="nextMonth()" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="{{ __('Mes siguiente') }}">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 0 1 0-1.414L10.586 10 7.293 6.707a1 1 0 0 1 1.414-1.414l4 4a1 1 0 0 1 0 1.414l-4 4a1 1 0 0 1-1.414 0z" clip-rule="evenodd" /></svg>
                </button>
            </div>

            <div class="mt-2 grid grid-cols-7 text-center text-xs font-medium text-slate-400 dark:text-slate-500">
                <template x-for="d in weekdays" :key="d"><span x-text="d" class="py-1"></span></template>
            </div>

            <div class="grid grid-cols-7 gap-0.5">
                <template x-for="(week, wi) in weeks" :key="wi">
                    <template x-for="day in week" :key="day.iso">
                        <button
                            type="button"
                            x-on:click="pick(day)"
                            :disabled="day.disabled"
                            x-text="day.label"
                            :class="{
                                'bg-primary-600 text-white font-semibold hover:bg-primary-600': day.iso === value,
                                'text-slate-700 dark:text-slate-200 hover:bg-primary-50 dark:hover:bg-primary-500/10': day.iso !== value && day.inMonth && ! day.disabled,
                                'text-slate-300 dark:text-slate-600': (! day.inMonth || day.disabled) && day.iso !== value,
                                'ring-1 ring-inset ring-primary-400': day.isToday && day.iso !== value,
                                'cursor-not-allowed': day.disabled,
                            }"
                            class="h-8 rounded-md text-sm tabular-nums transition-colors"
                        ></button>
                    </template>
                </template>
            </div>

            <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                <button type="button" x-on:click="clear()" class="rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">{{ __('Borrar') }}</button>
                <button type="button" x-on:click="goToday()" class="rounded-md px-2 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10">{{ __('Hoy') }}</button>
            </div>
        </div>
    </template>

    @if ($help && ! $errorMsg)
        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
    @endif

    @if ($errorMsg)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $errorMsg }}</p>
    @endif
</div>
