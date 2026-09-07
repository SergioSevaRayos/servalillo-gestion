<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Clientes') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Carga inicial desde Access: :cmd', ['cmd' => 'php artisan clientes:importar archivo.csv']) }}</p>
        </div>
        @can('create', \App\Models\Client::class)
            <x-ui.button wire:click="create">{{ __('Nuevo cliente') }}</x-ui.button>
        @endcan
    </div>

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar por nombre, teléfono, CIF, código, población…') }}"
            class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:max-w-xs lg:text-sm"
        />
        <select wire:model.live="status" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Activos e inactivos') }}</option>
            <option value="active">{{ __('Solo activos') }}</option>
            <option value="inactive">{{ __('Solo inactivos') }}</option>
            <option value="prospect">{{ __('Pendiente valoración') }}</option>
        </select>
        <select wire:model.live="kind" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Reparto y viajes') }}</option>
            @foreach ($serviceKinds as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="type" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Todos los tipos') }}</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="schedule" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Cualquier periodicidad') }}</option>
            <option value="due">{{ __('Le toca reparto') }}</option>
        </select>
        <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <x-ui.sortable-th field="name" :sort="$sort" :direction="$direction">{{ __('Nombre') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="phone" :sort="$sort" :direction="$direction">{{ __('Teléfono') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="city" :sort="$sort" :direction="$direction">{{ __('Población') }}</x-ui.sortable-th>
                <th>{{ __('Tipo') }}</th>
                <x-ui.sortable-th field="typical_quantity" :sort="$sort" :direction="$direction" class="text-right">{{ __('Litros') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="frequency_days" :sort="$sort" :direction="$direction">{{ __('Periodicidad') }}</x-ui.sortable-th>
                <th>{{ __('Estado') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                <tr wire:key="client-{{ $client->id }}">
                    <td data-label="{{ __('Nombre') }}" class="font-medium text-slate-800 dark:text-slate-100">
                        <a href="{{ route('clients.show', $client) }}" wire:navigate class="hover:text-primary-600 dark:hover:text-primary-400">{{ $client->name }}</a>
                        @if ($client->service_kind === \App\Enums\ServiceKind::Viaje)
                            <x-ui.badge variant="primary" class="ml-1">{{ __('Viaje') }}</x-ui.badge>
                        @endif
                        @if ($client->isDeliveryDue())
                            <x-ui.badge variant="warning" class="ml-1">{{ __('toca reparto') }}</x-ui.badge>
                        @endif
                    </td>
                    <td data-label="{{ __('Teléfono') }}" class="whitespace-nowrap">
                        @if ($client->phone)
                            <a href="tel:{{ $client->phone }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $client->phone }}</a>
                            @if ($client->secondary_phone)
                                <span class="block text-xs text-slate-400">{{ $client->secondary_phone }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td data-label="{{ __('Población') }}">{{ $client->city ?? '—' }}</td>
                    <td data-label="{{ __('Tipo') }}">{{ $client->client_type?->label() ?? '—' }}</td>
                    <td data-label="{{ __('Litros') }}" class="text-right">{{ $client->typical_quantity !== null ? number_format($client->typical_quantity, 0, ',', '.').' L' : '—' }}</td>
                    <td data-label="{{ __('Periodicidad') }}">{{ $client->frequencyLabel() }}</td>
                    <td data-label="{{ __('Estado') }}">
                        @if ($client->isProspect())
                            <x-ui.badge variant="warning">{{ __('Pendiente valoración') }}</x-ui.badge>
                        @else
                            <x-ui.badge :variant="$client->is_active ? 'success' : 'neutral'">{{ $client->is_active ? __('Activo') : __('Inactivo') }}</x-ui.badge>
                        @endif
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button href="{{ route('clients.show', $client) }}" variant="ghost" size="sm">{{ __('Ver') }}</x-ui.button>
                            @if ($client->isProspect())
                                @can('approve', $client)
                                    <x-ui.button variant="ghost" size="sm" wire:click="approve({{ $client->id }})">{{ __('Aprobar') }}</x-ui.button>
                                @endcan
                                @can('delete', $client)
                                    <x-ui.button variant="ghost" size="sm"
                                        wire:click="discard({{ $client->id }})"
                                        wire:confirm="{{ __('¿Descartar a :name? Se borrará definitivamente.', ['name' => $client->name]) }}"
                                        class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10">{{ __('Descartar') }}</x-ui.button>
                                @endcan
                            @else
                                @can('update', $client)
                                    <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $client->id }})">{{ __('Editar') }}</x-ui.button>
                                @endcan
                                @can('delete', $client)
                                    <x-ui.button variant="ghost" size="sm"
                                        wire:click="delete({{ $client->id }})"
                                        wire:confirm="{{ __('¿Eliminar a :name?', ['name' => $client->name]) }}"
                                        class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10">{{ __('Eliminar') }}</x-ui.button>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8"><x-ui.empty-state title="{{ __('No hay clientes') }}" description="{{ __('Importa desde Access o crea el primero.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $clients->links() }}</div>

    <x-modal name="client-form" max-width="4xl">
        <form wire:submit="save" class="p-5">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                @if ($this->form->editing)
                    {{ __('Editar cliente') }}
                @elseif ($this->form->status === 'prospect')
                    {{ __('Nuevo pre-cliente (pendiente valoración)') }}
                @else
                    {{ __('Nuevo cliente') }}
                @endif
            </h3>

            <div class="mt-4 max-h-[72vh] overflow-y-auto px-1 -mx-1 themed-scrollbar">
                <x-clients.form-fields
                    :delivery-types="$this->deliveryTypes"
                    :types="$types"
                    :status="$this->form->status"
                    :editing="(bool) $this->form->editing" />
            </div>

            <div class="mt-4 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>
</div>
