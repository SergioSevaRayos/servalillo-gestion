<div>
    <x-maintenance.tabs />

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Buscar por mensaje, excepción o URL…') }}"
                class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
            />
            <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
            <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
            <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
        </div>

        @if ($oldCount > 0)
            <x-ui.button
                variant="secondary" size="sm"
                wire:click="purgeOld"
                wire:confirm="{{ __('¿Eliminar :n errores de más de 30 días?', ['n' => $oldCount]) }}"
            >{{ __('Purgar > 30 días (:n)', ['n' => $oldCount]) }}</x-ui.button>
        @endif
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Excepción') }}</th>
                <th>{{ __('Mensaje') }}</th>
                <th>{{ __('Petición') }}</th>
                <th>{{ __('Usuario') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($errors as $error)
                <tr wire:key="error-{{ $error->id }}">
                    <td data-label="{{ __('Fecha') }}" class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $error->occurred_at->format('d/m/Y H:i') }}</td>
                    <td data-label="{{ __('Excepción') }}" class="font-mono text-xs text-slate-700 dark:text-slate-200">{{ class_basename($error->exception_class) ?: '—' }}</td>
                    <td data-label="{{ __('Mensaje') }}">{{ \Illuminate\Support\Str::limit($error->message, 80) }}</td>
                    <td data-label="{{ __('Petición') }}" class="text-xs text-slate-400">
                        @if ($error->url)<span class="font-medium text-slate-500 dark:text-slate-400">{{ $error->method }}</span> {{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::after($error->url, '://'), 40) }}@else —@endif
                    </td>
                    <td data-label="{{ __('Usuario') }}">{{ $error->user?->name ?? '—' }}</td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="ghost" size="sm" wire:click="show({{ $error->id }})">{{ __('Ver') }}</x-ui.button>
                            <x-ui.button
                                variant="ghost" size="sm"
                                wire:click="deleteLog({{ $error->id }})"
                                wire:confirm="{{ __('¿Eliminar este registro?') }}"
                                class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                            >{{ __('Eliminar') }}</x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6"><x-ui.empty-state title="{{ __('Sin errores registrados') }}" description="{{ __('El sistema no ha registrado excepciones 5xx con estos filtros.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $errors->links() }}</div>

    <x-modal name="error-detail" max-width="2xl">
        @if ($this->selected)
            <div class="p-6">
                <h3 class="font-mono text-base font-medium text-slate-900 dark:text-white">{{ $this->selected->exception_class }}</h3>
                <p class="mt-2 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200">{{ $this->selected->message }}</p>

                <dl class="mt-4 grid grid-cols-1 gap-2 text-xs text-slate-500 dark:text-slate-400 sm:grid-cols-2">
                    <div><dt class="font-medium text-slate-600 dark:text-slate-300">{{ __('Origen') }}</dt><dd class="break-all font-mono">{{ $this->selected->file }}:{{ $this->selected->line }}</dd></div>
                    <div><dt class="font-medium text-slate-600 dark:text-slate-300">{{ __('Cuándo') }}</dt><dd>{{ $this->selected->occurred_at->format('d/m/Y H:i:s') }}</dd></div>
                    <div><dt class="font-medium text-slate-600 dark:text-slate-300">{{ __('Petición') }}</dt><dd class="break-all">{{ $this->selected->method }} {{ $this->selected->url ?: '—' }}</dd></div>
                    <div><dt class="font-medium text-slate-600 dark:text-slate-300">{{ __('Usuario') }}</dt><dd>{{ $this->selected->user?->name ?? '—' }}</dd></div>
                </dl>

                @php $trace = data_get($this->selected->context, 'trace', []); @endphp
                @if ($trace)
                    <p class="mt-4 text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ __('Traza') }}</p>
                    <ol class="themed-scrollbar mt-1 max-h-64 space-y-1 overflow-y-auto rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                        @foreach ($trace as $i => $frame)
                            <li class="break-all">
                                <span class="text-slate-400">#{{ $i }}</span>
                                {{ data_get($frame, 'file', '[internal]') }}:{{ data_get($frame, 'line', '?') }}
                                <span class="text-slate-400">→ {{ data_get($frame, 'function') }}()</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                <div class="mt-6 flex justify-between">
                    <x-ui.button
                        variant="ghost" type="button"
                        wire:click="deleteLog({{ $this->selected->id }})"
                        wire:confirm="{{ __('¿Eliminar este registro?') }}"
                        class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                    >{{ __('Eliminar') }}</x-ui.button>
                    <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
