<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Buscar por nombre, email o código…') }}"
                class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
            />
            <select wire:model.live="status" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm">
                <option value="all">{{ __('Todos') }}</option>
                <option value="active">{{ __('Activos') }}</option>
                <option value="inactive">{{ __('Inactivos') }}</option>
            </select>
        </div>

        @can('create', \App\Models\Driver::class)
            <x-ui.button wire:click="create">{{ __('Nuevo chofer') }}</x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <x-ui.sortable-th field="employee_code" :sort="$sort" :direction="$direction">{{ __('Código') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="name" :sort="$sort" :direction="$direction">{{ __('Nombre') }}</x-ui.sortable-th>
                <th>{{ __('Email') }}</th>
                <th>{{ __('Carné') }}</th>
                <th>{{ __('Estado') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($drivers as $driver)
                <tr wire:key="driver-{{ $driver->id }}">
                    <td data-label="{{ __('Código') }}" class="font-medium text-slate-800 dark:text-slate-100">{{ $driver->employee_code }}</td>
                    <td data-label="{{ __('Nombre') }}">{{ $driver->user->name }}</td>
                    <td data-label="{{ __('Email') }}">{{ $driver->user->email }}</td>
                    <td data-label="{{ __('Carné') }}">
                        {{ $driver->license_number ?? '—' }}
                        @if ($driver->license_expiry)
                            <span class="text-xs text-slate-400">({{ $driver->license_expiry->format('d/m/Y') }})</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Estado') }}">
                        <x-ui.badge :variant="$driver->is_active ? 'success' : 'neutral'">
                            {{ $driver->is_active ? __('Activo') : __('Inactivo') }}
                        </x-ui.badge>
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            @can('update', $driver)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $driver->id }})">{{ __('Editar') }}</x-ui.button>
                            @endcan
                            @can('delete', $driver)
                                <x-ui.button
                                    variant="ghost" size="sm"
                                    wire:click="delete({{ $driver->id }})"
                                    wire:confirm="{{ __('¿Eliminar a :name? Esta acción no se puede deshacer.', ['name' => $driver->user->name]) }}"
                                    class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                                >{{ __('Eliminar') }}</x-ui.button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state title="{{ __('No hay chofers') }}" description="{{ __('Prueba a cambiar los filtros o crea el primero.') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $drivers->links() }}</div>

    <x-modal name="driver-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ $this->editing() ? __('Editar chofer') : __('Nuevo chofer') }}
            </h3>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="{{ __('Nombre completo') }}" wire:model="form.name" />
                <x-ui.input name="email" label="{{ __('Email') }}" type="email" wire:model="form.email" />
                <x-ui.input
                    name="password"
                    label="{{ __('Contraseña') }}"
                    type="password"
                    wire:model="form.password"
                    :help="$this->editing() ? __('Déjalo en blanco para no cambiarla.') : null"
                />
                <x-ui.input name="employee_code" label="{{ __('Código de empleado') }}" wire:model="form.employee_code" />
                <x-ui.input name="license_number" label="{{ __('Nº de carné') }}" wire:model="form.license_number" />
                <x-ui.date-input name="license_expiry" label="{{ __('Caducidad del carné') }}" wire:model="form.license_expiry" />
                <x-ui.input name="phone" label="{{ __('Teléfono') }}" wire:model="form.phone" />
                <div class="flex items-end pb-2">
                    <x-ui.checkbox name="is_active" label="{{ __('Activo') }}" wire:model="form.is_active" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>
</div>
