@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5']) }}>
    {{ $value ?? $slot }}
</label>
