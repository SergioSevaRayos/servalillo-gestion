@props(['value', 'required' => false])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5']) }}>
    {{ $value ?? $slot }}
    @if ($required)
        <span class="text-rose-500" aria-hidden="true">*</span>
    @endif
</label>
