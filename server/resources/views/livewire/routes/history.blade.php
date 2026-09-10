<div>
    <a href="{{ route('routes.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400">
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
        {{ __('Volver al listado de rutas') }}
    </a>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                {{ $route->truck->code }} · {{ $route->driver->user->name }}
                <x-ui.badge variant="{{ $route->service_kind === \App\Enums\ServiceKind::Viaje ? 'primary' : 'neutral' }}" class="ml-1">{{ $route->service_kind->label() }}</x-ui.badge>
            </h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Vigente desde el :from', ['from' => $route->valid_from->format('d/m/Y')]) }}
                @if ($route->valid_until)
                    {{ __('hasta el :until', ['until' => $route->valid_until->format('d/m/Y')]) }}
                @else
                    · {{ __('indefinida') }}
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
            <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
        </div>
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Estado') }}</th>
                <th>{{ __('Paradas') }}</th>
                <th>{{ __('Litros') }}</th>
                <th>{{ __('Jornada') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($days as $day)
                <tr wire:key="route-day-{{ $day->id }}">
                    <td data-label="{{ __('Fecha') }}" class="font-medium text-slate-800 dark:text-slate-100">{{ $day->route_date->format('d/m/Y') }}</td>
                    <td data-label="{{ __('Estado') }}">
                        @can('update', $route)
                            <button type="button" wire:click="openStatusModal({{ $day->id }})" title="{{ __('Cambiar estado') }}"
                                class="inline-flex items-center gap-1 rounded-full transition hover:opacity-75">
                                <x-ui.badge :variant="$day->status->badgeVariant()">{{ $day->status->label() }}</x-ui.badge>
                                <svg class="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                        @else
                            <x-ui.badge :variant="$day->status->badgeVariant()">{{ $day->status->label() }}</x-ui.badge>
                        @endcan
                    </td>
                    <td data-label="{{ __('Paradas') }}">
                        <span class="text-emerald-600 dark:text-emerald-400">{{ $day->completed_stops_count }}</span>
                        /
                        <span class="text-rose-600 dark:text-rose-400">{{ $day->failed_stops_count }}</span>
                        /
                        <span class="text-slate-500 dark:text-slate-400">{{ $day->pending_stops_count }}</span>
                        <span class="text-xs text-slate-400">({{ __('hechas/fallidas/pendientes') }})</span>
                    </td>
                    <td data-label="{{ __('Litros') }}">
                        @if ($day->liter_meter_start !== null)
                            {{ (int) $day->deliveredLiters() }} L
                            @if ($day->liter_meter_end !== null)
                                <span class="text-xs text-slate-400">({{ $day->liter_meter_start }} → {{ $day->liter_meter_end }})</span>
                            @endif
                            @if ($day->liter_discrepancy_note)
                                <x-ui.badge variant="warning" class="ml-1">{{ __('Descuadre') }}</x-ui.badge>
                            @endif
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Jornada') }}">
                        @if ($day->started_at)
                            {{ $day->started_at->format('H:i') }}
                            –
                            {{ $day->completed_at?->format('H:i') ?? '…' }}
                        @else
                            <span class="text-slate-400">{{ __('Sin empezar') }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="ghost" size="sm" wire:click="viewDay({{ $day->id }})">{{ __('Ver detalle') }}</x-ui.button>
                            @if ($day->stops_count > 0)
                                <x-ui.button variant="ghost" size="sm" wire:click="showDayMap({{ $day->id }})">{{ __('Ver recorrido') }}</x-ui.button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state title="{{ __('Sin historial') }}" description="{{ __('Todavía no hay días registrados para esta ruta.') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $days->links() }}</div>

    <x-modal name="day-detail" max-width="2xl">
        <div class="p-6">
            @if ($this->viewingDay)
                <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                    {{ __('Día :date', ['date' => $this->viewingDay->route_date->format('d/m/Y')]) }}
                </h3>

                <ul class="mt-4 max-h-[55vh] divide-y divide-slate-100 overflow-y-auto themed-scrollbar dark:divide-slate-800">
                    @forelse ($this->viewingDay->stops as $stop)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $stop->customer_name }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $stop->address }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.badge :variant="$stop->status->badgeVariant()">{{ $stop->status->label() }}</x-ui.badge>
                                @if ($stop->deliveryNote)
                                    <x-ui.button href="{{ route('delivery-notes.pdf', $stop->deliveryNote) }}" variant="ghost" size="sm">{{ __('Albarán') }}</x-ui.button>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="py-4 text-sm text-slate-500 dark:text-slate-400">{{ __('Sin paradas ese día.') }}</li>
                    @endforelse
                </ul>
            @endif

            <div class="mt-6 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
    </x-modal>

    <x-route-map-modal />
    <x-routes.status-picker-modal :route="$this->statusRoute" />
</div>
