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
                <x-ui.sortable-th field="route_date" :sort="$sort" :direction="$direction">{{ __('Fecha') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="status" :sort="$sort" :direction="$direction">{{ __('Estado') }}</x-ui.sortable-th>
                <x-ui.sortable-th field="stops_count" :sort="$sort" :direction="$direction">{{ __('Paradas') }}</x-ui.sortable-th>
                <th>{{ __('Litros') }}</th>
                <th>{{ __('Jornada') }}</th>
                <x-ui.sortable-th field="on_site_seconds" :sort="$sort" :direction="$direction">{{ __('En paradas') }}</x-ui.sortable-th>
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
                    <td data-label="{{ __('En paradas') }}">
                        @if ($day->on_site_seconds)
                            {{ \App\Support\Duration::humanShort((int) $day->on_site_seconds) }}
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="ghost" size="sm" wire:click="viewDay({{ $day->id }})">{{ __('Ver detalle') }}</x-ui.button>
                            @if ($day->stops_count > 0)
                                <x-ui.button variant="ghost" size="sm" wire:click="showDayMap({{ $day->id }})">{{ __('Ver recorrido') }}</x-ui.button>
                            @endif
                            @can('update', $route)
                                <x-ui.button variant="ghost" size="sm" wire:click="openReassignModal({{ $day->id }})">{{ __('Reasignar chofer') }}</x-ui.button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
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

                @if ($this->viewingDay->liter_meter_start !== null)
                    <p class="mt-1.5 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        @if ($this->viewingDay->liter_meter_end !== null)
                            {{ __('Contador: :inicio → :fin L', [
                                'inicio' => number_format($this->viewingDay->liter_meter_start, 0, ',', '.'),
                                'fin' => number_format($this->viewingDay->liter_meter_end, 0, ',', '.'),
                            ]) }}
                        @else
                            {{ __('Contador: :inicio L · esperado :esperado L', [
                                'inicio' => number_format($this->viewingDay->liter_meter_start, 0, ',', '.'),
                                'esperado' => number_format($this->viewingDay->literMeterExpected(), 0, ',', '.'),
                            ]) }}
                        @endif
                    </p>
                    @if ($this->viewingDay->liter_discrepancy_note)
                        <p class="mt-1 flex items-start gap-1 text-xs text-amber-600 dark:text-amber-400">
                            <svg class="mt-px h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            <span>{{ __('Ajuste de contador:') }} {{ $this->viewingDay->liter_discrepancy_note }}</span>
                        </p>
                    @endif
                @endif

                <ul class="mt-4 max-h-[55vh] divide-y divide-slate-100 overflow-y-auto themed-scrollbar dark:divide-slate-800">
                    @forelse ($this->viewingDay->stops as $stop)
                        @if ($leg = ($this->transitLegs[$stop->id] ?? null))
                            <li class="flex items-center gap-1.5 py-1.5 text-xs text-slate-400 dark:text-slate-500">
                                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-1.32-2.214l3-6a1.5 1.5 0 0 1 2.64 0l3 6a1.5 1.5 0 0 1-1.32 2.214H8.25Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25 12 15 3 8.25" /></svg>
                                {{ __('En ruta') }} {{ \App\Support\Duration::humanShort($leg['seconds']) }}
                                @if ($leg['avg_speed_kmh'] !== null)
                                    · {{ $leg['avg_speed_kmh'] }} km/h {{ __('de media') }}
                                    @if ($leg['max_speed_kmh'] !== null && $leg['max_speed_kmh'] !== $leg['avg_speed_kmh'])
                                        ({{ __('máx.') }} {{ $leg['max_speed_kmh'] }})
                                    @endif
                                @endif
                            </li>
                        @endif
                        <li class="flex flex-col gap-1 py-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $stop->customer_name }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $stop->address }}</p>
                                    <x-stop-dwell :stop="$stop" variant="line" />
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <x-ui.badge :variant="$stop->status->badgeVariant()">{{ $stop->status->label() }}</x-ui.badge>
                                    @if ($stop->deliveryNote)
                                        <x-ui.button href="{{ route('delivery-notes.pdf', $stop->deliveryNote) }}" variant="ghost" size="sm">{{ __('Albarán') }}</x-ui.button>
                                    @endif
                                </div>
                            </div>

                            @if ($stop->status === \App\Enums\RouteStopStatus::Completed)
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    @if ($stop->delivered_quantity !== null)
                                        <span>{{ __(':n L entregados', ['n' => number_format($stop->delivered_quantity, 0, ',', '.')]) }}</span>
                                    @endif
                                    @unless ($stop->counted_in_meter)
                                        <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                            <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM4 4l16 16" /></svg>
                                            {{ __('No pasa por el contador') }}
                                        </span>
                                    @endunless
                                    @if ($stop->deliveryType)
                                        @foreach ($stop->deliveryType->fields() as $field)
                                            @php $value = $stop->data[$field['key']] ?? null; @endphp
                                            @if ($value !== null && $value !== '')
                                                <span>{{ $field['label'] }}: {{ is_bool($value) ? ($value ? __('Sí') : __('No')) : $value }}</span>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            @elseif (in_array($stop->status, [\App\Enums\RouteStopStatus::Failed, \App\Enums\RouteStopStatus::Skipped], true) && $stop->failure_reason)
                                <p class="text-xs text-rose-600 dark:text-rose-400">{{ $stop->failure_reason }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="py-4 text-sm text-slate-500 dark:text-slate-400">{{ __('Sin paradas ese día.') }}</li>
                    @endforelse
                </ul>

                @if ($this->unplannedStops->isNotEmpty())
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                        <p class="flex items-center gap-1.5 text-sm font-medium text-amber-800 dark:text-amber-300">
                            <span aria-hidden="true">⚠️</span>
                            {{ __('Paradas no programadas') }}
                        </p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($this->unplannedStops as $unplanned)
                                <li class="flex items-center justify-between gap-3 text-xs text-amber-700 dark:text-amber-400">
                                    <span>
                                        {{ $unplanned->entered_at->format('H:i') }}
                                        –
                                        {{ $unplanned->left_at?->format('H:i') ?? __('en curso') }}
                                        @if ($unplanned->seconds !== null)
                                            · {{ \App\Support\Duration::humanShort($unplanned->seconds) }}
                                        @endif
                                    </span>
                                    <a
                                        href="{{ \App\Support\GoogleMaps::pointUrl($unplanned->latitude, $unplanned->longitude) }}"
                                        target="_blank" rel="noopener"
                                        class="shrink-0 font-medium text-amber-800 hover:underline dark:text-amber-300"
                                    >{{ __('Ver en el mapa') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif

            <div class="mt-6 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
    </x-modal>

    <x-route-map-modal />
    <x-routes.status-picker-modal :route="$this->statusRoute" />
    <x-routes.reassign-driver-modal :route-day="$this->reassignRouteDay" :drivers="$this->drivers" />
</div>
