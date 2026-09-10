<div>
    <a href="{{ route('routes.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400">
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
        {{ __('Volver al listado de rutas') }}
    </a>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ __('Quién lleva cada camión, de forma permanente. Cada día se crea sola la ruta de la fecha para cada asignación vigente.') }}
        </p>

        @can('create', \App\Models\TruckAssignment::class)
            <x-ui.button wire:click="create">{{ __('Nueva asignación') }}</x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Chofer') }}</th>
                <th>{{ __('Camión') }}</th>
                <th>{{ __('Desde') }}</th>
                <th>{{ __('Hasta') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($assignments as $assignment)
                <tr wire:key="assignment-{{ $assignment->id }}">
                    <td data-label="{{ __('Chofer') }}" class="font-medium text-slate-800 dark:text-slate-100">{{ $assignment->driver->user->name }}</td>
                    <td data-label="{{ __('Camión') }}">{{ $assignment->truck->code }}</td>
                    <td data-label="{{ __('Desde') }}">{{ $assignment->valid_from->format('d/m/Y') }}</td>
                    <td data-label="{{ __('Hasta') }}">
                        @if ($assignment->valid_until)
                            {{ $assignment->valid_until->format('d/m/Y') }}
                        @else
                            <x-ui.badge variant="primary">{{ __('Indefinida') }}</x-ui.badge>
                        @endif
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            @can('update', $assignment)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $assignment->id }})">{{ __('Editar') }}</x-ui.button>
                                @if (! $assignment->valid_until)
                                    <x-ui.button
                                        variant="ghost" size="sm"
                                        wire:click="finalize({{ $assignment->id }})"
                                        wire:confirm="{{ __('¿Finalizar hoy la asignación de :driver con :truck?', ['driver' => $assignment->driver->user->name, 'truck' => $assignment->truck->code]) }}"
                                    >{{ __('Finalizar') }}</x-ui.button>
                                @endif
                            @endcan
                            @can('delete', $assignment)
                                <x-ui.button
                                    variant="ghost" size="sm"
                                    wire:click="delete({{ $assignment->id }})"
                                    wire:confirm="{{ __('¿Eliminar esta asignación? Las rutas que ya haya generado no se tocan.') }}"
                                    class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                                >{{ __('Eliminar') }}</x-ui.button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <x-ui.empty-state title="{{ __('No hay asignaciones') }}" description="{{ __('Crea la primera para que sus rutas se generen solas cada día.') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $assignments->links() }}</div>

    <x-modal name="assignment-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ $this->editing() ? __('Editar asignación') : __('Nueva asignación') }}
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

                <x-ui.date-input name="valid_from" label="{{ __('Desde') }}" wire:model="form.valid_from" />

                <x-ui.date-input name="valid_until" label="{{ __('Hasta (vacío = indefinida)') }}" wire:model="form.valid_until" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>
</div>
