<div wire:poll.45s x-on:stops-reordered.window="$wire.call('reorderStops', $event.detail.fromRouteId, $event.detail.fromIds, $event.detail.toRouteId, $event.detail.toIds)">
    <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2">
        <div class="inline-flex shrink-0 rounded-lg border border-slate-200 bg-slate-100 p-0.5 dark:border-slate-700 dark:bg-slate-800">
            @foreach ($kinds as $k)
                <button type="button" wire:click="setKind('{{ $k->value }}')" @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-white text-primary-700 shadow-soft-sm dark:bg-slate-700 dark:text-primary-200' => $kind === $k->value,
                    'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $kind !== $k->value,
                ])>{{ $k->pluralLabel() }}</button>
            @endforeach
        </div>

        <div class="mx-1 hidden h-6 w-px bg-slate-200 dark:bg-slate-700 sm:block"></div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="secondary" size="sm" wire:click="previousDay">
                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
            </x-ui.button>

            <input type="date" wire:model.live="date" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm" />

            <x-ui.button variant="secondary" size="sm" wire:click="nextDay">
                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
            </x-ui.button>

            <x-ui.button variant="ghost" size="sm" wire:click="today">{{ __('Hoy') }}</x-ui.button>
        </div>

        @can('viewAny', \App\Models\Route::class)
            <a href="{{ route('routes.index') }}" wire:navigate class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400 sm:ml-auto">
                {{ __('Gestionar fichas de ruta →') }}
            </a>
        @endcan
    </div>

    {{-- Selector de columna en móvil (swipe entre columnas) --}}
    <div class="mb-3 flex gap-2 overflow-x-auto pb-1 md:hidden">
        <button type="button" @click="document.getElementById('col-unassigned').scrollIntoView({behavior:'smooth', inline:'start', block:'nearest'})" class="shrink-0 rounded-full bg-slate-900/5 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300">
            {{ __('Sin asignar') }}
        </button>
        @foreach ($routes as $route)
            <button type="button" @click="document.getElementById('col-{{ $route->id }}').scrollIntoView({behavior:'smooth', inline:'start', block:'nearest'})" class="shrink-0 rounded-full bg-slate-900/5 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300">
                {{ $route->truck->code }}
            </button>
        @endforeach
    </div>

    {{--
        Comportamiento Trello: el tablero completo NO crece verticalmente. Cada columna tiene una
        altura fija (header + botón fijos, lista con su propio scroll interno) y el scroll para
        moverse entre columnas es siempre horizontal, nunca vertical.
    --}}
    <div class="themed-scrollbar -mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-4 md:mx-0 md:snap-none md:px-0">
        {{-- Columna fija: Sin asignar --}}
        <div id="col-unassigned" class="flex h-[65vh] w-[88vw] max-w-sm shrink-0 snap-start flex-col md:h-[calc(100vh-14rem)] md:w-80 md:max-w-none md:shrink">
            <div class="glass mb-2 flex shrink-0 items-center justify-between rounded-2xl px-4 py-3">
                <div>
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Sin asignar') }}</p>
                    <p class="text-xs text-slate-400">{{ __(':n paradas', ['n' => $unassigned->count()]) }}</p>
                </div>
            </div>

            <ul data-stop-list class="themed-scrollbar min-h-0 flex-1 space-y-2 overflow-y-auto px-1.5 -mx-1.5">
                @foreach ($unassigned as $stop)
                    <x-routes.stop-card :stop="$stop" />
                @endforeach
            </ul>

            @can('create', \App\Models\RouteStop::class)
                <button wire:click="openCreateStop(null)" type="button" class="mt-2 w-full shrink-0 rounded-xl border border-dashed border-slate-300 py-2 text-sm text-slate-400 hover:border-primary-400 hover:text-primary-600 dark:border-slate-700 dark:hover:border-primary-500">
                    {{ __('+ Añadir parada') }}
                </button>
            @endcan
        </div>

        {{-- Una columna por ruta del día --}}
        @foreach ($routes as $route)
            <div id="col-{{ $route->id }}" class="flex h-[65vh] w-[88vw] max-w-sm shrink-0 snap-start flex-col md:h-[calc(100vh-14rem)] md:w-80 md:max-w-none md:shrink">
                <div class="glass mb-2 shrink-0 rounded-2xl px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $route->truck->code }} · {{ $route->driver->user->name }}</p>
                        <x-ui.badge :variant="$route->status->badgeVariant()">{{ $route->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-slate-400">{{ __(':n paradas', ['n' => $route->stops->count()]) }}</p>
                    @if ($route->liter_discrepancy_note)
                        <p class="mt-1 flex items-start gap-1 text-xs text-amber-600 dark:text-amber-400" title="{{ $route->liter_discrepancy_note }}">
                            <svg class="mt-px h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            <span class="line-clamp-2">{{ __('Ajuste de contador:') }} {{ $route->liter_discrepancy_note }}</span>
                        </p>
                    @endif
                </div>

                <ul data-stop-list data-route-id="{{ $route->id }}" class="themed-scrollbar min-h-0 flex-1 space-y-2 overflow-y-auto px-1.5 -mx-1.5">
                    @foreach ($route->stops as $stop)
                        <x-routes.stop-card :stop="$stop" />
                    @endforeach
                </ul>

                @can('create', \App\Models\RouteStop::class)
                    <button wire:click="openCreateStop({{ $route->id }})" type="button" class="mt-2 w-full shrink-0 rounded-xl border border-dashed border-slate-300 py-2 text-sm text-slate-400 hover:border-primary-400 hover:text-primary-600 dark:border-slate-700 dark:hover:border-primary-500">
                        {{ __('+ Añadir parada') }}
                    </button>
                @endcan
            </div>
        @endforeach

        @if ($routes->isEmpty())
            <div class="flex w-full items-center">
                <x-ui.empty-state
                    title="{{ __('No hay rutas para este día') }}"
                    description="{{ __('Créalas desde “Gestionar fichas de ruta” y vuelve aquí para repartir las paradas.') }}"
                />
            </div>
        @endif
    </div>

    <x-modal name="stop-form" max-width="3xl">
        <form wire:submit="saveStop" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ $this->form->editing ? __('Editar parada') : __('Nueva parada') }}
            </h3>

            <div class="mt-5 grid max-h-[65vh] grid-cols-1 gap-x-4 gap-y-3 overflow-y-auto px-1 -mx-1 themed-scrollbar sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.select name="service_kind" label="{{ __('Tipo de servicio') }}" wire:model="form.service_kind">
                    @foreach (\App\Enums\ServiceKind::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <div class="sm:col-span-1 lg:col-span-2">
                    <x-ui.input name="customer_name" label="{{ __('Cliente') }}" wire:model="form.customer_name" />
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-ui.input name="address" label="{{ __('Dirección') }}" wire:model="form.address" />
                </div>

                <x-ui.input name="contact_name" label="{{ __('Contacto') }}" wire:model="form.contact_name" />
                <x-ui.input name="contact_phone" label="{{ __('Teléfono') }}" wire:model="form.contact_phone" />

                <x-ui.select name="status" label="{{ __('Estado') }}" wire:model="form.status">
                    @foreach (\App\Enums\RouteStopStatus::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input name="planned_quantity" label="{{ __('Cantidad prevista') }}" type="number" step="0.01" wire:model="form.planned_quantity" />

                <div class="sm:col-span-1 lg:col-span-2">
                    <x-ui.select name="delivery_type_id" label="{{ __('Tipo de reparto') }}" wire:model.live="form.delivery_type_id" placeholder="{{ __('Sin tipo específico') }}">
                        @foreach ($this->deliveryTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @if ($this->selectedDeliveryType)
                    @foreach ($this->selectedDeliveryType->fields() as $field)
                        <div @class(['sm:col-span-2 lg:col-span-3' => $field['type'] === 'textarea'])>
                            @switch($field['type'])
                                @case('boolean')
                                    <div class="flex h-full items-end pb-2">
                                        <x-ui.checkbox :name="'data_'.$field['key']" :label="$field['label']" wire:model="form.data.{{ $field['key'] }}" />
                                    </div>
                                    @break
                                @case('select')
                                    <x-ui.select :name="'data_'.$field['key']" :label="$field['label']" wire:model="form.data.{{ $field['key'] }}" placeholder="{{ __('Selecciona…') }}">
                                        @foreach ($field['options'] ?? [] as $option)
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    @break
                                @case('textarea')
                                    <x-ui.textarea :name="'data_'.$field['key']" :label="$field['label']" :rows="2" wire:model="form.data.{{ $field['key'] }}" />
                                    @break
                                @case('date')
                                    <x-ui.input :name="'data_'.$field['key']" :label="$field['label']" type="date" wire:model="form.data.{{ $field['key'] }}" />
                                    @break
                                @case('number')
                                    <x-ui.input :name="'data_'.$field['key']" :label="$field['label'].(!empty($field['unit']) ? ' ('.$field['unit'].')' : '')" type="number" wire:model="form.data.{{ $field['key'] }}" />
                                    @break
                                @default
                                    <x-ui.input :name="'data_'.$field['key']" :label="$field['label']" wire:model="form.data.{{ $field['key'] }}" />
                            @endswitch
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="mt-5 flex items-center justify-between gap-3">
                @if ($this->form->editing)
                    <x-ui.button
                        type="button" variant="ghost" size="sm"
                        wire:click="deleteStop({{ $this->form->editing->id }})"
                        wire:confirm="{{ __('¿Eliminar esta parada? Esta acción no se puede deshacer.') }}"
                        class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                    >{{ __('Eliminar') }}</x-ui.button>
                @else
                    <span></span>
                @endif

                <div class="flex gap-3">
                    <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
                </div>
            </div>
        </form>
    </x-modal>
</div>
