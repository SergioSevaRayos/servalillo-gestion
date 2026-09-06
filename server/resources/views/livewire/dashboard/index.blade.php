@php
    $fmt = fn (?float $n) => $n === null ? '—' : number_format($n, 0, ',', '.');
    $pct = fn (?float $n) => $n === null ? '—' : rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',').'%';
@endphp

<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Panel estadístico') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Del :from al :to', [
                    'from' => \Illuminate\Support\Carbon::parse($stats['range']['from'])->isoFormat('D MMM YYYY'),
                    'to' => \Illuminate\Support\Carbon::parse($stats['range']['to'])->isoFormat('D MMM YYYY'),
                ]) }}
            </p>
        </div>

        {{-- Selector de rango --}}
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-soft-sm dark:border-slate-700 dark:bg-slate-800">
            @foreach (['7d' => '7 días', '30d' => '30 días', '90d' => '90 días', 'year' => 'Año'] as $key => $label)
                <button
                    type="button"
                    wire:click="setRange('{{ $key }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                        'bg-primary-600 text-white shadow-soft-sm' => $range === $key,
                        'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' => $range !== $key,
                    ])
                >{{ __($label) }}</button>
            @endforeach
        </div>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
        <x-ui.stat-card label="{{ __('Rutas activas hoy') }}" :value="$stats['kpis']['routes_today_operational']" />
        <x-ui.stat-card label="{{ __('Entregas completadas') }}" :value="$fmt($stats['kpis']['stops_completed'])" />
        <x-ui.stat-card label="{{ __('Tasa de éxito') }}" :value="$pct($stats['kpis']['success_rate'])" />
        <x-ui.stat-card label="{{ __('Litros entregados') }}" :value="$fmt($stats['kpis']['liters_delivered']).' L'" />
        <x-ui.stat-card label="{{ __('Cumplimiento') }}" :value="$pct($stats['kpis']['fill_rate'])" />
        <x-ui.stat-card label="{{ __('Paradas sin asignar') }}" :value="$stats['kpis']['unassigned_stops']" />
    </div>

    {{--
        Gráficos: bloque wire:ignore para que el morph de Livewire no toque los <canvas> al
        cambiar de rango. El componente Alpine `statsCharts` los crea una vez y los actualiza
        con el evento `stats-updated` que emite setRange() (ver resources/js/app.js).
    --}}
    <div wire:ignore x-data="statsCharts(@js($chartData))" class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Paradas cerradas por día') }}</p>
            <div class="h-64"><canvas x-ref="daily"></canvas></div>
        </x-ui.card>

        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Estado de las rutas') }}</p>
            <div class="h-64"><canvas x-ref="routeStatus"></canvas></div>
        </x-ui.card>

        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Litros entregados por tipo de reparto') }}</p>
            <div class="h-64"><canvas x-ref="volume"></canvas></div>
        </x-ui.card>

        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Entregas por chofer') }}</p>
            <div class="h-64"><canvas x-ref="driver"></canvas></div>
        </x-ui.card>
    </div>

    {{-- Volumen: planificado vs entregado --}}
    <div class="mt-4">
        <x-ui.card>
            <div class="flex items-baseline justify-between">
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Volumen del periodo') }}</p>
                <p class="text-xs text-slate-400">{{ __('paradas ya cerradas') }}</p>
            </div>
            @php $planned = (float) $stats['volume']['planned']; $delivered = (float) $stats['volume']['delivered']; @endphp
            <div class="mt-3 space-y-2">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500 dark:text-slate-400">{{ __('Planificado') }}</span>
                    <span class="font-medium text-slate-900 dark:text-white">{{ $fmt($planned) }} L</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full bg-primary-500" style="width: {{ $planned > 0 ? min(100, $delivered / $planned * 100) : 0 }}%"></div>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500 dark:text-slate-400">{{ __('Entregado') }}</span>
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $fmt($delivered) }} L · {{ $pct($stats['kpis']['fill_rate']) }}</span>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Tabla por chofer --}}
    <div class="mt-4">
        <x-ui.card :padded="false">
            <p class="p-5 pb-0 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Rendimiento por chofer') }}</p>
            <x-ui.table class="mt-3">
                <thead>
                    <tr>
                        <th>{{ __('Chofer') }}</th>
                        <th class="text-right">{{ __('Completadas') }}</th>
                        <th class="text-right">{{ __('Falladas') }}</th>
                        <th class="text-right">{{ __('Tasa fallo') }}</th>
                        <th class="text-right">{{ __('Litros') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stats['by_driver'] as $row)
                        <tr>
                            <td data-label="{{ __('Chofer') }}">{{ $row['driver'] }}</td>
                            <td data-label="{{ __('Completadas') }}" class="text-right">{{ $row['completed'] }}</td>
                            <td data-label="{{ __('Falladas') }}" class="text-right">{{ $row['failed'] }}</td>
                            <td data-label="{{ __('Tasa fallo') }}" class="text-right">
                                @if ($row['failure_rate'] !== null && $row['failure_rate'] > 0)
                                    <x-ui.badge variant="{{ $row['failure_rate'] >= 15 ? 'danger' : 'warning' }}">{{ $pct($row['failure_rate']) }}</x-ui.badge>
                                @else
                                    <span class="text-slate-400">{{ $pct($row['failure_rate']) }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('Litros') }}" class="text-right">{{ $fmt($row['liters']) }} L</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-sm text-slate-400">{{ __('Sin entregas en este periodo.') }}</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card>
    </div>

    {{-- Tabla por camión --}}
    <div class="mt-4">
        <x-ui.card :padded="false">
            <p class="p-5 pb-0 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Uso por camión') }}</p>
            <x-ui.table class="mt-3">
                <thead>
                    <tr>
                        <th>{{ __('Camión') }}</th>
                        <th class="text-right">{{ __('Días con ruta') }}</th>
                        <th class="text-right">{{ __('Km') }}</th>
                        <th class="text-right">{{ __('Litros movidos') }}</th>
                        <th class="text-right">{{ __('Capacidad') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stats['by_truck'] as $row)
                        <tr>
                            <td data-label="{{ __('Camión') }}">{{ $row['truck'] }}</td>
                            <td data-label="{{ __('Días con ruta') }}" class="text-right">{{ $row['route_days'] }}</td>
                            <td data-label="{{ __('Km') }}" class="text-right">{{ $fmt($row['km']) }}</td>
                            <td data-label="{{ __('Litros movidos') }}" class="text-right">{{ $fmt($row['liters']) }} L</td>
                            <td data-label="{{ __('Capacidad') }}" class="text-right text-slate-400">{{ $row['capacity'] ? $fmt($row['capacity']).' L' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-sm text-slate-400">{{ __('Sin actividad en este periodo.') }}</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card>
    </div>
</div>
