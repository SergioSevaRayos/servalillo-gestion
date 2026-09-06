<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Buscar por matrícula, código o modelo…') }}"
                class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
            />
            <select wire:model.live="status" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm">
                <option value="all">{{ __('Todos') }}</option>
                <option value="active">{{ __('Activos') }}</option>
                <option value="inactive">{{ __('Inactivos') }}</option>
            </select>
        </div>

        @can('create', \App\Models\Truck::class)
            <x-ui.button wire:click="create">
                {{ __('Nuevo camión') }}
            </x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <x-ui.sortable-th field="code" :sort="$sort" :direction="$direction">{{ __('Código') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="plate" :sort="$sort" :direction="$direction">{{ __('Matrícula') }}</x-ui.sortable-th>
                <th>{{ __('Modelo') }}</th>
                <x-ui.sortable-th field="capacity_liters" :sort="$sort" :direction="$direction">{{ __('Capacidad') }}</x-ui.sortable-th>
                <th>{{ __('Estado') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($trucks as $truck)
                <tr wire:key="truck-{{ $truck->id }}">
                    <td data-label="{{ __('Código') }}" class="font-medium text-slate-800 dark:text-slate-100">{{ $truck->code }}</td>
                    <td data-label="{{ __('Matrícula') }}">{{ $truck->plate }}</td>
                    <td data-label="{{ __('Modelo') }}">{{ $truck->model ?? '—' }}</td>
                    <td data-label="{{ __('Capacidad') }}">{{ $truck->capacity_liters ? number_format($truck->capacity_liters, 0, ',', '.').' L' : '—' }}</td>
                    <td data-label="{{ __('Estado') }}">
                        <x-ui.badge :variant="$truck->is_active ? 'success' : 'neutral'">
                            {{ $truck->is_active ? __('Activo') : __('Inactivo') }}
                        </x-ui.badge>
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            @can('update', $truck)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $truck->id }})">{{ __('Editar') }}</x-ui.button>
                            @endcan
                            @can('delete', $truck)
                                <x-ui.button
                                    variant="ghost" size="sm"
                                    wire:click="delete({{ $truck->id }})"
                                    wire:confirm="{{ __('¿Eliminar el camión :code? Esta acción no se puede deshacer.', ['code' => $truck->code]) }}"
                                    class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                                >{{ __('Eliminar') }}</x-ui.button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state title="{{ __('No hay camiones') }}" description="{{ __('Prueba a cambiar los filtros o crea el primero.') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">
        {{ $trucks->links() }}
    </div>

    <x-modal name="truck-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ $this->editing() ? __('Editar camión') : __('Nuevo camión') }}
            </h3>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input name="code" label="{{ __('Código') }}" wire:model="form.code" placeholder="C-05" />
                <x-ui.input name="plate" label="{{ __('Matrícula') }}" wire:model="form.plate" placeholder="1234-ABC" />
                <x-ui.input name="model" label="{{ __('Modelo') }}" wire:model="form.model" />
                <x-ui.input name="year" label="{{ __('Año') }}" type="number" wire:model="form.year" />
                <x-ui.input name="capacity_liters" label="{{ __('Capacidad (L)') }}" type="number" wire:model="form.capacity_liters" />
                <x-ui.input name="compartments" label="{{ __('Compartimentos') }}" type="number" wire:model="form.compartments" />
                <x-ui.input name="odometer" label="{{ __('Contador actual (km)') }}" type="number" wire:model="form.odometer" />
                <div class="flex items-end pb-2">
                    <x-ui.checkbox name="is_active" label="{{ __('Activo') }}" wire:model="form.is_active" />
                </div>
                <div class="sm:col-span-2">
                    <x-ui.textarea name="notes" label="{{ __('Notas') }}" wire:model="form.notes" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>
</div>
