@props(['label' => null, 'name'])

@php $id = $attributes->get('id', $name); @endphp

<label for="{{ $id }}" class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 select-none">
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="checkbox"
        {{ $attributes->merge([
            'class' => 'rounded border-slate-300 text-primary-600 shadow-soft-sm focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800',
        ]) }}
    />
    @if ($label)
        {{ $label }}
    @endif
</label>
