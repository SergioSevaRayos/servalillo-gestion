@props(['label' => null, 'name', 'error' => null, 'help' => null, 'placeholder' => null])

@php
$id = $attributes->get('id', $name);
$errorMsg = $error ?? ($errors->first($name) ?: null);
@endphp

<div>
    @if ($label)
        <x-input-label :for="$id" :value="$label" />
    @endif

    <select
        id="{{ $id }}"
        name="{{ $name }}"
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg shadow-soft-sm sm:text-sm dark:bg-slate-800 dark:text-slate-100 '
                . ($errorMsg
                    ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500 dark:border-rose-500/50'
                    : 'border-slate-300 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700'),
        ]) }}
    >
        @if ($placeholder)
            <option value="" disabled selected>{{ $placeholder }}</option>
        @endif
        {{ $slot }}
    </select>

    @if ($help && ! $errorMsg)
        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
    @endif

    @if ($errorMsg)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $errorMsg }}</p>
    @endif
</div>
