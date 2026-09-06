{{-- Selector de tema accesible (system/light/dark), persistido en cookie + BD (ver ThemeController). --}}
<div
    x-data
    role="radiogroup"
    aria-label="{{ __('Tema') }}"
    class="glass inline-flex items-center gap-0.5 rounded-full p-1"
>
    @foreach ([
        'light' => ['label' => 'Claro', 'icon' => 'sun'],
        'system' => ['label' => 'Automático', 'icon' => 'monitor'],
        'dark' => ['label' => 'Oscuro', 'icon' => 'moon'],
    ] as $value => $option)
        <button
            type="button"
            role="radio"
            @click="$store.theme.set('{{ $value }}')"
            :aria-checked="$store.theme.current === '{{ $value }}'"
            :class="$store.theme.current === '{{ $value }}'
                ? 'bg-white text-primary-700 shadow-soft-sm dark:bg-slate-700 dark:text-primary-300'
                : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
            class="grid h-8 w-8 place-items-center rounded-full transition duration-150 ease-out"
            :title="'{{ $option['label'] }}'"
        >
            <span class="sr-only">{{ $option['label'] }}</span>

            @switch($option['icon'])
                @case('sun')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-4 w-4" aria-hidden="true">
                        <circle cx="12" cy="12" r="4" />
                        <path stroke-linecap="round" d="M12 2.5v2M12 19.5v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2.5 12h2M19.5 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
                    </svg>
                    @break
                @case('moon')
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path d="M20.8 14.5A8.5 8.5 0 0 1 9.5 3.2a.6.6 0 0 0-.7-.85A9.7 9.7 0 1 0 21.65 15.2a.6.6 0 0 0-.85-.7Z" />
                    </svg>
                    @break
                @default
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-4 w-4" aria-hidden="true">
                        <rect x="3" y="4.5" width="18" height="12" rx="2" />
                        <path stroke-linecap="round" d="M8.5 20h7M12 16.5V20" />
                    </svg>
            @endswitch
        </button>
    @endforeach
</div>
