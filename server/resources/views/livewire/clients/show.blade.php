@php use App\Enums\RouteStopStatus; @endphp

<div class="mx-auto max-w-4xl">
    <a href="{{ route('clients.index') }}" wire:navigate class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('← Clientes') }}</a>

    <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $client->name }}</h1>
                @if ($client->isProspect())
                    <x-ui.badge variant="warning">{{ __('Pendiente valoración') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="primary">{{ $client->service_kind->label() }}</x-ui.badge>
                    <x-ui.badge :variant="$client->is_active ? 'success' : 'neutral'">{{ $client->is_active ? __('Activo') : __('Inactivo') }}</x-ui.badge>
                    @if ($client->isDeliveryDue())
                        <x-ui.badge variant="warning">{{ __('Le toca reparto') }}</x-ui.badge>
                    @endif
                @endif
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $client->client_type?->label() }}
                @if ($client->tax_id) · {{ $client->tax_id }} @endif
                @if ($client->external_ref) · <span class="text-slate-400">{{ __('cód. :ref', ['ref' => $client->external_ref]) }}</span> @endif
            </p>
        </div>
        <div class="flex gap-2">
            @if ($client->isProspect())
                @can('approve', $client)
                    <x-ui.button size="sm" wire:click="approve">{{ __('Aprobar') }}</x-ui.button>
                @endcan
                @can('update', $client)
                    <x-ui.button variant="secondary" size="sm" wire:click="edit">{{ __('Editar') }}</x-ui.button>
                @endcan
                @can('delete', $client)
                    <x-ui.button variant="secondary" size="sm" wire:click="discard"
                        wire:confirm="{{ __('¿Descartar a :name? Se borrará definitivamente.', ['name' => $client->name]) }}"
                        class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10">{{ __('Descartar') }}</x-ui.button>
                @endcan
            @else
                @can('update', $client)
                    <x-ui.button variant="secondary" size="sm" wire:click="planDelivery">{{ __('Planificar :kind', ['kind' => \Illuminate\Support\Str::lower($client->service_kind->label())]) }}</x-ui.button>
                    <x-ui.button size="sm" wire:click="edit">{{ __('Editar') }}</x-ui.button>
                @endcan
            @endif
        </div>
    </div>

    {{-- KPIs del histórico (solo clientes reales) --}}
    @unless ($client->isProspect())
        <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <x-ui.stat-card label="{{ __('Repartos') }}" :value="$this->stats['count']" />
            <x-ui.stat-card label="{{ __('Litros servidos') }}" :value="number_format($this->stats['total_liters'], 0, ',', '.').' L'" />
            <x-ui.stat-card label="{{ __('Media en parada') }}" :value="$this->stats['avg_on_site_seconds'] !== null ? \App\Support\Duration::humanShort($this->stats['avg_on_site_seconds']) : '—'" />
            <x-ui.stat-card label="{{ __('Último reparto') }}" :value="$this->stats['last_on']?->format('d/m/Y') ?? $client->last_served_on?->format('d/m/Y') ?? '—'" />
            <x-ui.stat-card label="{{ __('Próximo estimado') }}" :value="$client->nextDeliveryOn()?->format('d/m/Y') ?? '—'" />
        </div>
    @endunless

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Datos --}}
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Contacto y ubicación') }}</h2>
                <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    @foreach ([
                        __('Persona de contacto') => $client->contact_name,
                        __('Teléfono') => $client->phone,
                        __('Teléfono secundario') => $client->secondary_phone,
                        __('Email') => $client->email,
                        __('Dirección') => $client->address,
                        __('Población') => trim(($client->postal_code ? $client->postal_code.' ' : '').($client->city ?? '')) ?: null,
                        __('Provincia') => $client->province,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs text-slate-400">{{ $label }}</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                    @if ($client->latitude && $client->longitude)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-400">{{ __('Coordenadas') }}</dt>
                            <dd class="text-slate-700 dark:text-slate-200">
                                {{ $client->latitude }}, {{ $client->longitude }}
                                <a href="{{ \App\Support\GoogleMaps::pointUrl((float) $client->latitude, (float) $client->longitude) }}" target="_blank" rel="noopener"
                                    class="ml-2 text-primary-600 hover:underline dark:text-primary-400">{{ __('abrir en mapa ↗') }}</a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Datos del suministro') }}</h2>
                <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    @foreach ([
                        __('Tipo de agua') => $client->water_type?->label(),
                        __('Cantidad habitual') => $client->quantityLabel(),
                        __('Distancia depósito–camión') => $client->tank_distance_m !== null ? $client->tank_distance_m.' m' : null,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs text-slate-400">{{ $label }}</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            @if ($client->isProspect())
                @if ($client->notes)
                    <x-ui.card>
                        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Observaciones de la llamada') }}</h2>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $client->notes }}</p>
                    </x-ui.card>
                @endif
            @else
                <x-ui.card>
                    <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Reparto habitual') }}</h2>
                    <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        @foreach ([
                            __('Calendario') => $client->frequencyLabel(),
                            __('Próximo estimado') => $client->nextDeliveryOn()?->format('d/m/Y'),
                            __('Capacidad del depósito') => $client->tank_capacity_liters ? number_format($client->tank_capacity_liters, 0, ',', '.').' L' : null,
                            __('Canal de albarán') => $client->preferred_channel ? ($client->preferred_channel === 'email' ? __('Email') : __('Entrega en mano')) : null,
                            __('Precio') => $client->priceLabel(),
                            __('Forma de pago') => $client->payment_terms,
                            __('Bomba propia') => $client->requires_own_pump ? __('Sí') : __('No'),
                        ] as $label => $value)
                            <div>
                                <dt class="text-xs text-slate-400">{{ $label }}</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($client->access_notes || $client->notes)
                        <div class="mt-4 space-y-2 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                            @if ($client->access_notes)
                                <p><span class="text-xs font-medium uppercase text-slate-400">{{ __('Acceso') }}</span><br>{{ $client->access_notes }}</p>
                            @endif
                            @if ($client->notes)
                                <p><span class="text-xs font-medium uppercase text-slate-400">{{ __('Notas') }}</span><br>{{ $client->notes }}</p>
                            @endif
                        </div>
                    @endif
                </x-ui.card>
            @endif

        </div>

        {{-- Auditoría --}}
        <div>
            <x-ui.card>
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Cambios recientes') }}</h2>
                <ul class="mt-3 space-y-3 text-sm">
                    @forelse ($this->audits() as $audit)
                        <li>
                            <p class="text-slate-600 dark:text-slate-300">
                                <span class="font-medium">{{ ['created' => __('Creado'), 'updated' => __('Modificado'), 'deleted' => __('Eliminado')][$audit->event] ?? $audit->event }}</span>
                                {{ __('por :name', ['name' => $audit->user?->name ?? __('Sistema')]) }}
                            </p>
                            <p class="text-xs text-slate-400">
                                {{ $audit->created_at->format('d/m/Y H:i') }}
                                @php $fields = collect(array_keys($audit->getModified()))->implode(', '); @endphp
                                @if ($fields) · {{ \Illuminate\Support\Str::limit($fields, 50) }} @endif
                            </p>
                        </li>
                    @empty
                        <li class="text-slate-400">{{ __('Sin cambios registrados.') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </div>

    {{-- Histórico de repartos (ancho completo, solo clientes reales) --}}
    @unless ($client->isProspect())
    <x-ui.card :padded="false" class="mt-6">
        <div class="flex items-baseline justify-between p-5 pb-0">
            <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Histórico de repartos') }}</h2>
            <span class="text-xs text-slate-400">{{ __('emparejado por :key', ['key' => $client->tax_id ? 'CIF' : 'nombre']) }}</span>
        </div>
        <x-ui.table class="mt-3">
            <thead>
                <tr>
                    <th>{{ __('Fecha') }}</th>
                    <th>{{ __('Tipo') }}</th>
                    <th class="text-right">{{ __('Previsto') }}</th>
                    <th class="text-right">{{ __('Entregado') }}</th>
                    <th>{{ __('Estado') }}</th>
                    <th>{{ __('Chofer') }}</th>
                    <th>{{ __('Albarán') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->history() as $stop)
                    <tr wire:key="hist-{{ $stop->id }}">
                        <td data-label="{{ __('Fecha') }}" class="whitespace-nowrap">{{ $stop->route?->route_date?->format('d/m/Y') ?? '—' }}</td>
                        <td data-label="{{ __('Tipo') }}" class="whitespace-nowrap">{{ $stop->deliveryType?->name ?? '—' }}</td>
                        <td data-label="{{ __('Previsto') }}" class="text-right">{{ $stop->planned_quantity !== null ? number_format($stop->planned_quantity, 0, ',', '.') : '—' }}</td>
                        <td data-label="{{ __('Entregado') }}" class="text-right">{{ $stop->delivered_quantity !== null ? number_format($stop->delivered_quantity, 0, ',', '.') : '—' }}</td>
                        <td data-label="{{ __('Estado') }}"><x-ui.badge :variant="$stop->status->badgeVariant()">{{ $stop->status->label() }}</x-ui.badge></td>
                        <td data-label="{{ __('Chofer') }}" class="whitespace-nowrap">{{ $stop->route?->driver?->user?->name ?? '—' }}</td>
                        <td data-label="{{ __('Albarán') }}">
                            @if ($stop->deliveryNote)
                                <a href="{{ route('delivery-notes.pdf', $stop->deliveryNote) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $stop->deliveryNote->number }}</a>
                            @else — @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state title="{{ __('Sin repartos anteriores') }}" description="{{ __('Aparecerán cuando haya paradas a nombre de este cliente.') }}" /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
    @endunless

    <x-modal name="client-form" max-width="4xl">
        <form wire:submit="save" class="p-5">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Editar cliente') }}</h3>
            <div class="mt-4 max-h-[72vh] overflow-y-auto px-1 -mx-1 pb-2 themed-scrollbar">
                <x-clients.form-fields :delivery-types="$deliveryTypes" :types="\App\Enums\ClientType::options()" :form="$form" :status="$form->status" editing />
            </div>
            <div class="mt-4 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>
</div>
