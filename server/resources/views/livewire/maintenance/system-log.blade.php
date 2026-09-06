@php
    $levelVariant = [
        'debug' => 'neutral', 'info' => 'primary', 'notice' => 'primary',
        'warning' => 'warning', 'error' => 'danger', 'critical' => 'danger',
        'alert' => 'danger', 'emergency' => 'danger',
    ];
@endphp

<div>
    <x-maintenance.tabs />

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
        <select wire:model.live="file" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            @forelse ($this->files() as $f)
                <option value="{{ $f }}">{{ $f }}</option>
            @empty
                <option value="">{{ __('Sin archivos de log') }}</option>
            @endforelse
        </select>
        <select wire:model.live="level" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Todos los niveles') }}</option>
            @foreach ($levels as $l)
                <option value="{{ $l }}">{{ ucfirst($l) }}</option>
            @endforeach
        </select>
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar en el log…') }}"
            class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:max-w-xs lg:text-sm"
        />
    </div>

    <x-ui.card :padded="false">
        @forelse ($this->entries() as $entry)
            <details class="group border-b border-slate-100 last:border-0 dark:border-slate-800" wire:key="log-{{ $loop->index }}">
                <summary class="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <span class="whitespace-nowrap font-mono text-xs text-slate-400">{{ $entry['datetime'] }}</span>
                    <x-ui.badge :variant="$levelVariant[$entry['level']] ?? 'neutral'">{{ strtoupper($entry['level']) }}</x-ui.badge>
                    <span class="min-w-0 flex-1 truncate text-slate-700 group-open:whitespace-normal dark:text-slate-200">{{ $entry['message'] }}</span>
                </summary>
                @if ($entry['body'] !== '')
                    <pre class="themed-scrollbar max-h-96 overflow-auto bg-slate-50 px-4 py-3 font-mono text-xs leading-relaxed text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">{{ $entry['body'] }}</pre>
                @endif
            </details>
        @empty
            <x-ui.empty-state
                title="{{ __('Sin líneas que mostrar') }}"
                description="{{ __('El archivo está vacío o ninguna entrada coincide con los filtros.') }}"
            />
        @endforelse
    </x-ui.card>

    <p class="mt-3 text-xs text-slate-400">
        {{ __('Se muestran las entradas más recientes de la cola del archivo (máx. 300).') }}
    </p>
</div>
