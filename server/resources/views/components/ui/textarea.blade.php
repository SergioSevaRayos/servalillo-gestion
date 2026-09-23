@props(['label' => null, 'name', 'error' => null, 'help' => null, 'rows' => 3])

@php
$id = $attributes->get('id', $name);
// Ver el mismo comentario en components/ui/input.blade.php: el error va bajo la ruta de
// wire:model ("form.campo"), no bajo $name a secas.
$errorKey = $attributes->whereStartsWith('wire:model')->isNotEmpty() ? $attributes->wire('model')->value() : $name;
$errorMsg = $error ?? ($errors->first($errorKey) ?: null);
@endphp

<div>
    @if ($label)
        <x-input-label :for="$id" :value="$label" />
    @endif

    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg shadow-soft-sm sm:text-sm placeholder:text-slate-400 dark:placeholder:text-slate-500 dark:bg-slate-800 dark:text-slate-100 '
                . ($errorMsg
                    ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500 dark:border-rose-500/50'
                    : 'border-slate-300 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700'),
        ]) }}
    >{{ $slot }}</textarea>

    @if ($help && ! $errorMsg)
        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
    @endif

    @if ($errorMsg)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $errorMsg }}</p>
    @endif
</div>
