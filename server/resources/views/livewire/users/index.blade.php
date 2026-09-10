<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Buscar por nombre o email…') }}"
                class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
            />
            <select wire:model.live="role" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm">
                <option value="all">{{ __('Todos los roles') }}</option>
                <option value="administrador">{{ __('Administrador') }}</option>
                <option value="mantenimiento">{{ __('Mantenimiento') }}</option>
            </select>
        </div>

        @can('create', \App\Models\User::class)
            <x-ui.button wire:click="create">{{ __('Nuevo usuario') }}</x-ui.button>
        @endcan
    </div>

    <p class="mb-4 text-xs text-slate-400 dark:text-slate-500">
        {{ __('Solo cuentas de personal (Administrador / Mantenimiento). Los chofers se gestionan en Chofers.') }}
    </p>

    <x-ui.table>
        <thead>
            <tr>
                <x-ui.sortable-th field="name" :sort="$sort" :direction="$direction">{{ __('Nombre') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="email" :sort="$sort" :direction="$direction">{{ __('Email') }}</x-ui.sortable-th>
                <th>{{ __('Rol') }}</th>
                <th>{{ __('Estado') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr wire:key="user-{{ $user->id }}">
                    <td data-label="{{ __('Nombre') }}" class="font-medium text-slate-800 dark:text-slate-100">{{ $user->name }}</td>
                    <td data-label="{{ __('Email') }}">{{ $user->email }}</td>
                    <td data-label="{{ __('Rol') }}">
                        <x-ui.badge variant="primary">{{ ucfirst($user->getRoleNames()->first() ?? '—') }}</x-ui.badge>
                    </td>
                    <td data-label="{{ __('Estado') }}">
                        <x-ui.badge :variant="$user->is_active ? 'success' : 'neutral'">
                            {{ $user->is_active ? __('Activo') : __('Inactivo') }}
                        </x-ui.badge>
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            @can('update', $user)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $user->id }})">{{ __('Editar') }}</x-ui.button>
                            @endcan
                            @can('delete', $user)
                                <x-ui.button
                                    variant="ghost" size="sm"
                                    wire:click="delete({{ $user->id }})"
                                    wire:confirm="{{ __('¿Eliminar a :name? Esta acción no se puede deshacer.', ['name' => $user->name]) }}"
                                    class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                                >{{ __('Eliminar') }}</x-ui.button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <x-ui.empty-state title="{{ __('No hay usuarios') }}" description="{{ __('Prueba a cambiar los filtros o crea el primero.') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $users->links() }}</div>

    <x-modal name="user-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ $this->editing() ? __('Editar usuario') : __('Nuevo usuario') }}
            </h3>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="{{ __('Nombre completo') }}" wire:model="form.name" />
                <x-ui.input name="email" label="{{ __('Email') }}" type="email" wire:model="form.email" />
                <x-ui.password-input
                    name="password"
                    label="{{ __('Contraseña') }}"
                    wire:model="form.password"
                    suggest
                    :help="$this->editing() ? __('Déjalo en blanco para no cambiarla.') : __('Mínimo 10 caracteres, con letras y números.')"
                />
                <x-ui.input name="phone" label="{{ __('Teléfono') }}" wire:model="form.phone" />
                <x-ui.select name="role" label="{{ __('Rol') }}" wire:model="form.role">
                    <option value="administrador">{{ __('Administrador') }}</option>
                    <option value="mantenimiento">{{ __('Mantenimiento') }}</option>
                </x-ui.select>
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
