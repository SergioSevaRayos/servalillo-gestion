<div>
    <a href="{{ route('routes.board') }}" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400">
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
        {{ __('Volver al tablero') }}
    </a>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Buscar por camión, chofer o nombre…') }}"
                class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
            />
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ __('Cada ruta (camión + chofer) es una ficha permanente: aparece sola en el tablero todos los días mientras esté vigente.') }}
            </p>
        </div>

        @can('create', \App\Models\Route::class)
            <x-ui.button wire:click="create">{{ __('Nueva ruta') }}</x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Camión') }}</th>
                <th>{{ __('Chofer') }}</th>
                <th>{{ __('Tipo') }}</th>
                <x-ui.sortable-th field="valid_from" :sort="$sort" :direction="$direction">{{ __('Desde') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="valid_until" :sort="$sort" :direction="$direction">{{ __('Hasta') }}</x-ui.sortable-th>
                <th>{{ __('Días registrados') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($routes as $route)
                <tr wire:key="route-{{ $route->id }}">
                    <td data-label="{{ __('Camión') }}" class="font-medium text-slate-800 dark:text-slate-100">{{ $route->truck->code }}</td>
                    <td data-label="{{ __('Chofer') }}">{{ $route->driver->user->name }}</td>
                    <td data-label="{{ __('Tipo') }}">
                        <x-ui.badge variant="{{ $route->service_kind === \App\Enums\ServiceKind::Viaje ? 'primary' : 'neutral' }}">{{ $route->service_kind->label() }}</x-ui.badge>
                    </td>
                    <td data-label="{{ __('Desde') }}">{{ $route->valid_from->format('d/m/Y') }}</td>
                    <td data-label="{{ __('Hasta') }}">
                        @if ($route->valid_until)
                            {{ $route->valid_until->format('d/m/Y') }}
                        @else
                            <x-ui.badge variant="primary">{{ __('Indefinida') }}</x-ui.badge>
                        @endif
                    </td>
                    <td data-label="{{ __('Días registrados') }}">{{ $route->route_days_count }}</td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex flex-wrap justify-end gap-2">
                            <a href="{{ route('routes.history', $route) }}" wire:navigate>
                                <x-ui.button variant="ghost" size="sm">{{ __('Historial') }}</x-ui.button>
                            </a>
                            @can('update', $route)
                                <x-ui.button variant="ghost" size="sm" wire:click="openTerminals({{ $route->id }})">{{ __('Terminales') }}</x-ui.button>
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $route->id }})">{{ __('Editar') }}</x-ui.button>
                                @if (! $route->valid_until)
                                    <x-ui.button
                                        variant="ghost" size="sm"
                                        wire:click="finalize({{ $route->id }})"
                                        wire:confirm="{{ __('¿Finalizar hoy la ruta de :driver con :truck?', ['driver' => $route->driver->user->name, 'truck' => $route->truck->code]) }}"
                                    >{{ __('Finalizar') }}</x-ui.button>
                                @endif
                            @endcan
                            @can('delete', $route)
                                <x-ui.button
                                    variant="ghost" size="sm"
                                    wire:click="delete({{ $route->id }})"
                                    wire:confirm="{{ __('¿Eliminar esta ruta? Su historial de días no se toca.') }}"
                                    class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                                >{{ __('Eliminar') }}</x-ui.button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state title="{{ __('No hay rutas') }}" description="{{ __('Crea la primera para que aparezca sola en el tablero cada día.') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $routes->links() }}</div>

    <p class="mt-4 text-xs text-slate-400 dark:text-slate-500">
        {{ __('Las paradas de cada día se gestionan desde el tablero. El detalle de lo hecho un día concreto está en "Historial".') }}
    </p>

    <x-modal name="route-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ $this->editing() ? __('Editar ruta') : __('Nueva ruta') }}
            </h3>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.select name="truck_id" label="{{ __('Camión') }}" wire:model="form.truck_id" placeholder="{{ __('Selecciona un camión') }}">
                    @foreach ($this->trucks as $truck)
                        <option value="{{ $truck->id }}">{{ $truck->code }} — {{ $truck->plate }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select name="driver_id" label="{{ __('Chofer') }}" wire:model="form.driver_id" placeholder="{{ __('Selecciona un chofer') }}">
                    @foreach ($this->drivers as $driver)
                        <option value="{{ $driver->id }}">{{ $driver->user->name }}</option>
                    @endforeach
                </x-ui.select>

                <div class="sm:col-span-2">
                    <x-ui.select name="service_kind" label="{{ __('Tipo de servicio') }}" wire:model="form.service_kind">
                        @foreach (\App\Enums\ServiceKind::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.date-input name="valid_from" label="{{ __('Desde') }}" wire:model="form.valid_from" />

                <x-ui.date-input name="valid_until" label="{{ __('Hasta (vacío = indefinida)') }}" wire:model="form.valid_until" />

                <div class="sm:col-span-2">
                    <x-ui.input name="name" label="{{ __('Nombre (opcional)') }}" wire:model="form.name" />
                </div>
                <div class="sm:col-span-2">
                    <x-ui.textarea name="notes" label="{{ __('Notas') }}" wire:model="form.notes" :rows="2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>

    <x-routes.terminals-modal :route="$this->terminalsRoute" />
</div>
