<div wire:poll.10s>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Avisos') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Configura cuándo quieres que la app te avise. Los avisos llegan a la campana, una vez al producirse y otra al resolverse.') }}
            </p>
        </div>
        {{-- Sin botón de "evaluar": se comprueba solo. Esto solo informa de que está vivo. --}}
        <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span class="relative flex h-2 w-2" aria-hidden="true">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
            </span>
            {{ __('Se actualiza solo') }}@if ($lastRun) · {{ __('última comprobación a las :h', ['h' => $lastRun->format('H:i:s')]) }}@endif
        </p>
    </div>

    {{-- Avisos activos --}}
    <section class="mb-8">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Activos ahora') }}</h2>
        @forelse ($active as $event)
            <a href="{{ $event->url }}" wire:navigate wire:key="act-{{ $event->id }}"
                class="surface mb-2 flex items-start justify-between gap-3 rounded-xl border-l-4 border-amber-500 p-3 hover:bg-slate-50 dark:hover:bg-slate-800">
                <div>
                    <p class="font-medium text-slate-900 dark:text-white">{{ $event->rule_type->label() }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ $event->message }}</p>
                </div>
                <span class="whitespace-nowrap text-xs text-slate-400">{{ $event->opened_at->diffForHumans() }}</span>
            </a>
        @empty
            <p class="surface rounded-xl p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ __('Todo en orden: no hay ningún aviso activo.') }}</p>
        @endforelse
    </section>

    {{-- Configuración --}}
    <form wire:submit="save">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Configuración') }}</h2>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @foreach ($types as $type)
                <div class="surface rounded-xl p-4" wire:key="rule-{{ $type->value }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-slate-900 dark:text-white">{{ __($type->label()) }}</h3>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __($type->description()) }}</p>
                        </div>
                        <x-ui.checkbox name="enabled_{{ $type->value }}" label="{{ __('Activo') }}" wire:model="form.items.{{ $type->value }}.enabled" />
                    </div>

                    <div class="mt-3 max-w-[12rem]">
                        @if ($type->usesTime())
                            <x-ui.input name="time_{{ $type->value }}" type="time" label="{{ __('A partir de las') }}" wire:model="form.items.{{ $type->value }}.time" />
                        @else
                            <x-ui.input name="threshold_{{ $type->value }}" type="number" inputmode="numeric"
                                label="{{ __('Umbral') }} ({{ $type->unit() }})" wire:model="form.items.{{ $type->value }}.threshold" />
                        @endif
                    </div>

                    {{-- Solo los avisos que se evalúan por dispositivo: todos o solo algunos. --}}
                    @if ($type->usesDevices())
                        @php $scope = $form->items[$type->value]['scope'] ?? 'all'; @endphp
                        <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                            <x-ui.select name="scope_{{ $type->value }}" label="{{ __('Aplicar a') }}" wire:model.live="form.items.{{ $type->value }}.scope">
                                <option value="all">{{ __('Todos los dispositivos') }}</option>
                                <option value="some">{{ __('Solo algunos dispositivos') }}</option>
                            </x-ui.select>

                            @if ($scope === 'some')
                                <div class="mt-2 space-y-1.5">
                                    @forelse ($devices as $device)
                                        <x-ui.checkbox name="dev_{{ $type->value }}_{{ $device->id }}"
                                            value="{{ $device->id }}"
                                            wire:model="form.items.{{ $type->value }}.device_ids"
                                            label="{{ $device->driver?->user?->name ?? $device->label }}{{ $device->driver ? ' · '.$device->label : '' }}{{ $device->is_active ? '' : ' ('.__('inactivo').')' }}" />
                                    @empty
                                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Todavía no hay ningún dispositivo enrolado.') }}</p>
                                    @endforelse
                                </div>
                                @error('form.items.'.$type->value.'.device_ids')
                                    <p class="mt-1.5 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                @enderror
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex justify-end">
            <x-ui.button type="submit">{{ __('Guardar avisos') }}</x-ui.button>
        </div>
    </form>

    {{-- Historial de resueltos: filtros, borrado manual y purga automática --}}
    <section class="mt-8">
        <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Historial de avisos resueltos') }}</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Los resueltos se borran solos a los :n días. Los avisos activos nunca se borran.', ['n' => $retentionDays]) }}
                </p>
            </div>
            <p class="text-xs text-slate-400">{{ trans_choice(':count aviso resuelto guardado|:count avisos resueltos guardados', $resolvedTotal) }}</p>
        </div>

        <div class="mb-3 flex flex-col gap-3 lg:flex-row lg:items-center">
            <select wire:model.live="historyType" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
                <option value="all">{{ __('Todos los tipos') }}</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ __($type->label()) }}</option>
                @endforeach
            </select>
            <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
            <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
            <x-ui.button type="button" variant="ghost" size="sm" wire:click="resetHistoryFilters">{{ __('Limpiar') }}</x-ui.button>

            <div class="flex items-center gap-2 lg:ml-auto">
                @if (count($selected) > 0)
                    <x-ui.button type="button" variant="danger" size="sm" wire:click="deleteSelected"
                        wire:confirm="{{ __('¿Eliminar los avisos seleccionados? No se puede deshacer.') }}">
                        {{ __('Eliminar seleccionados') }} ({{ count($selected) }})
                    </x-ui.button>
                @endif
                @if ($history->total() > 0)
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="deleteFiltered"
                        wire:confirm="{{ __('¿Vaciar :n aviso(s) resuelto(s) de esta lista? No se puede deshacer.', ['n' => $history->total()]) }}">
                        {{ __('Vaciar esta lista') }}
                    </x-ui.button>
                @endif
            </div>
        </div>

        @if ($history->isEmpty())
            <p class="surface rounded-xl p-4 text-sm text-slate-500 dark:text-slate-400">{{ __('No hay avisos resueltos con estos filtros.') }}</p>
        @else
            <div class="surface divide-y divide-slate-100 rounded-xl dark:divide-slate-800">
                <label class="flex items-center gap-3 px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                    <input type="checkbox" wire:click="toggleSelectAll"
                        @checked($history->pluck('id')->every(fn ($id) => in_array($id, array_map('intval', $selected), true)))
                        class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800" />
                    {{ __('Seleccionar los de esta página') }}
                </label>
                @foreach ($history as $event)
                    <label class="flex cursor-pointer items-start gap-3 p-3" wire:key="rec-{{ $event->id }}">
                        <input type="checkbox" value="{{ $event->id }}" wire:model.live="selected"
                            class="mt-1 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ __($event->rule_type->label()) }} · {{ $event->subject_label }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Desde :a hasta :b', ['a' => $event->opened_at->format('d/m/Y H:i'), 'b' => $event->resolved_at->format($event->resolved_at->isSameDay($event->opened_at) ? 'H:i' : 'd/m/Y H:i')]) }}
                            </p>
                        </div>
                        <x-ui.badge variant="success">{{ __('Resuelto') }}</x-ui.badge>
                    </label>
                @endforeach
            </div>

            <div class="mt-3">{{ $history->links() }}</div>
        @endif
    </section>
</div>
