<div>
    <a href="{{ route('drivers.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400">
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
        {{ __('Volver al listado de chofers') }}
    </a>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('Diario de :name', ['name' => $driver->user->name]) }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Incidencias y anotaciones de oficina sobre este chofer, buenas o malas.') }}</p>
        </div>

        @can('create', \App\Models\DriverLog::class)
            <x-ui.button wire:click="create">{{ __('Nueva incidencia') }}</x-ui.button>
        @endcan
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar en el texto…') }}"
            class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
        />
        <select wire:model.live="category" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm">
            <option value="all">{{ __('Todas las categorías') }}</option>
            @foreach ($this->categories as $c)
                <option value="{{ $c->value }}">{{ $c->label() }}</option>
            @endforeach
        </select>
        <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
        <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
    </div>

    <div class="space-y-3">
        @forelse ($logs as $log)
            <div wire:key="log-{{ $log->id }}" class="surface rounded-xl p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ $log->occurred_on->format('d/m/Y') }}</span>
                        <x-ui.badge :variant="$log->category->badgeVariant()">{{ $log->category->label() }}</x-ui.badge>
                    </div>
                    <div class="flex items-center gap-2">
                        @can('update', $log)
                            <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $log->id }})">{{ __('Editar') }}</x-ui.button>
                        @endcan
                        @can('delete', $log)
                            <x-ui.button
                                variant="ghost" size="sm"
                                wire:click="delete({{ $log->id }})"
                                wire:confirm="{{ __('¿Eliminar esta incidencia? Esta acción no se puede deshacer.') }}"
                                class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                            >{{ __('Eliminar') }}</x-ui.button>
                        @endcan
                    </div>
                </div>

                <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $log->body }}</p>

                <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">
                    {{ __('Anotado por :name el :date', ['name' => $log->creator?->name ?? __('Sistema'), 'date' => $log->created_at->format('d/m/Y H:i')]) }}
                    @if ($log->wasEdited())
                        · <span class="text-amber-600 dark:text-amber-400">
                            {{ __('Editado por :name el :date', ['name' => $log->editor?->name ?? __('Sistema'), 'date' => $log->updated_at->format('d/m/Y H:i')]) }}
                        </span>
                        <button type="button" wire:click="viewHistory({{ $log->id }})" class="text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Ver cambios') }}</button>
                    @endif
                </p>
            </div>
        @empty
            <x-ui.empty-state title="{{ __('Sin incidencias') }}" description="{{ __('Todavía no hay nada anotado para este chofer.') }}" />
        @endforelse
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>

    <x-modal name="log-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ $this->editing() ? __('Editar incidencia') : __('Nueva incidencia') }}
            </h3>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.date-input name="occurred_on" label="{{ __('Fecha') }}" wire:model="form.occurred_on" />

                <x-ui.select name="category" label="{{ __('Categoría') }}" wire:model="form.category">
                    @foreach (\App\Enums\DriverLogCategory::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                <div class="sm:col-span-2">
                    <x-ui.textarea name="body" label="{{ __('Anotación') }}" wire:model="form.body" :rows="5" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>

    <x-modal name="log-history" max-width="2xl">
        @if ($this->historyLog)
            <div class="p-6">
                <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Historial de cambios') }}</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Creada por :name el :date', ['name' => $this->historyLog->creator?->name ?? __('Sistema'), 'date' => $this->historyLog->created_at->format('d/m/Y H:i')]) }}
                </p>

                <div class="mt-4 max-h-[55vh] space-y-4 overflow-y-auto themed-scrollbar px-1 -mx-1">
                    @forelse ($this->historyAudits as $audit)
                        @php $modified = $audit->getModified(); @endphp
                        <div class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                            <p class="font-medium text-slate-700 dark:text-slate-200">
                                {{ $audit->user?->name ?? __('Sistema') }} · {{ $audit->created_at->format('d/m/Y H:i') }}
                            </p>
                            <ul class="mt-2 space-y-1 text-xs">
                                @forelse ($modified as $field => $values)
                                    <li>
                                        {{-- json_encode, no (string): un valor casteado (p. ej. la categoría, un enum
                                        nativo) no implementa __toString() y (string) $enum revienta la vista. --}}
                                        <span class="font-medium text-slate-600 dark:text-slate-300">{{ $field }}:</span>
                                        <span class="text-rose-600 dark:text-rose-400">{{ \Illuminate\Support\Str::limit(json_encode($values['old'] ?? null, JSON_UNESCAPED_UNICODE), 80) }}</span>
                                        →
                                        <span class="text-emerald-700 dark:text-emerald-400">{{ \Illuminate\Support\Str::limit(json_encode($values['new'] ?? null, JSON_UNESCAPED_UNICODE), 80) }}</span>
                                    </li>
                                @empty
                                    <li class="text-slate-400">{{ __('Sin cambios de campos registrados.') }}</li>
                                @endforelse
                            </ul>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">{{ __('Sin historial todavía.') }}</p>
                    @endforelse
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
