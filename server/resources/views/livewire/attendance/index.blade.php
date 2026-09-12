@php
    $today = $this->today;
    $working = $today && $today->in_at && ! $today->out_at;
    $finished = $today && $today->out_at;
@endphp

<div class="mx-auto max-w-3xl">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Fichar') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Registro de tu jornada de hoy.') }}</p>
    </div>

    <x-ui.card>
        <div class="flex flex-col items-center gap-4 py-4 text-center">
            @if ($finished)
                <x-ui.badge variant="success">{{ __('Jornada cerrada') }}</x-ui.badge>
                <p class="text-3xl font-semibold text-slate-800 dark:text-slate-100">
                    {{ \App\Support\Duration::humanShort($today->total_seconds ?? 0) }}
                </p>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Entrada :in · Salida :out', ['in' => $today->in_at->format('H:i'), 'out' => $today->out_at->format('H:i')]) }}
                </p>
            @elseif ($working)
                <x-ui.badge variant="primary">{{ __('Trabajando desde :time', ['time' => $today->in_at->format('H:i')]) }}</x-ui.badge>
            @else
                <x-ui.badge variant="neutral">{{ __('Sin fichar hoy') }}</x-ui.badge>
            @endif

            <div
                x-data="{ busy: false }"
                x-on:click="
                    if (busy) return;
                    busy = true;
                    const action = {{ $working ? 'true' : 'false' }} ? 'punchOut' : 'punchIn';
                    const finish = (lat, lng) => { $wire.call(action, lat, lng).then(() => { busy = false; }); };
                    if (! navigator.geolocation) { finish(null, null); return; }
                    navigator.geolocation.getCurrentPosition(
                        (pos) => finish(pos.coords.latitude, pos.coords.longitude),
                        () => finish(null, null),
                        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
                    );
                "
            >
                @if ($finished)
                    <x-ui.button variant="secondary" disabled>{{ __('Jornada ya cerrada') }}</x-ui.button>
                @else
                    <x-ui.button size="lg" x-bind:disabled="busy" :variant="$working ? 'danger' : 'primary'">
                        <span x-show="! busy">{{ $working ? __('Fichar salida') : __('Fichar entrada') }}</span>
                        <span x-show="busy" x-cloak>{{ __('Fichando…') }}</span>
                    </x-ui.button>
                @endif
            </div>

            @if ($today && ($today->in_out_of_bounds || $today->out_out_of_bounds))
                <p class="text-xs text-amber-600 dark:text-amber-400">
                    {{ __('Este fichaje quedó marcado fuera de la zona habitual — administración lo revisará.') }}
                </p>
            @endif
        </div>
    </x-ui.card>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-lg font-medium text-slate-800 dark:text-slate-100">{{ __('Últimos 30 días') }}</h2>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" size="sm" wire:click="exportXml">{{ __('Exportar (formato legal)') }}</x-ui.button>
            <x-ui.button variant="secondary" size="sm" wire:click="exportPdf">{{ __('Exportar (PDF)') }}</x-ui.button>
        </div>
    </div>

    <x-ui.table class="mt-3">
        <thead>
            <tr>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Entrada') }}</th>
                <th>{{ __('Salida') }}</th>
                <th class="text-right">{{ __('Horas') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->history as $row)
                <tr wire:key="attendance-{{ $row->id }}">
                    <td data-label="{{ __('Fecha') }}">{{ $row->date->format('d/m/Y') }}</td>
                    <td data-label="{{ __('Entrada') }}">{{ $row->in_at?->format('H:i') ?? '—' }}</td>
                    <td data-label="{{ __('Salida') }}">{{ $row->out_at?->format('H:i') ?? '—' }}</td>
                    <td data-label="{{ __('Horas') }}" class="text-right">
                        {{ $row->total_seconds !== null ? \App\Support\Duration::humanShort($row->total_seconds) : '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        <x-ui.empty-state title="{{ __('Todavía no hay fichajes') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
