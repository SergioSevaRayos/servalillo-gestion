{{-- Cabecera de columna ordenable. El componente Livewire debe exponer sortBy($field), $sort, $direction. --}}
@props(['field', 'sort', 'direction'])

<th {{ $attributes->merge(['class' => '']) }}>
    <button
        type="button"
        wire:click="sortBy('{{ $field }}')"
        class="inline-flex items-center gap-1 font-medium text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100"
    >
        {{ $slot }}
        <svg
            class="h-3.5 w-3.5 shrink-0 transition {{ $sort === $field ? 'opacity-100' : 'opacity-0' }} {{ $sort === $field && $direction === 'desc' ? 'rotate-180' : '' }}"
            viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"
        >
            <path fill-rule="evenodd" d="M10 3a1 1 0 01.7.29l4 4a1 1 0 01-1.4 1.42L10 5.42 6.7 8.7a1 1 0 01-1.4-1.42l4-4A1 1 0 0110 3Z" clip-rule="evenodd" />
        </svg>
    </button>
</th>
