@php
    $pct = fn (?float $n) => $n === null ? '—' : rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',').'%';
@endphp

<div wire:poll.60s>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Depósitos') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Nivel de agua en vivo (proyecto SGRA).') }}
            </p>
        </div>

        <x-ui.button variant="secondary" :href="$this->publicUrl()" target="_blank" rel="noopener">
            {{ __('Ver panel completo') }}
        </x-ui.button>
    </div>

    @if (empty($this->tanks))
        <x-ui.card>
            <x-ui.empty-state
                title="{{ __('No se ha podido conectar con los depósitos SGRA ahora mismo') }}"
                description="{{ __('Puede ser un corte de red o que el sistema esté apagado. Los datos se reintentan automáticamente.') }}"
            />
        </x-ui.card>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->tanks as $tank)
                <x-ui.card wire:key="tank-{{ $tank['id'] }}">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-medium text-slate-800 dark:text-slate-100">{{ $tank['name'] }}</h2>
                        @if ($tank['fill_pct'] === null)
                            <x-ui.badge variant="neutral">{{ __('Sin datos') }}</x-ui.badge>
                        @elseif (! $tank['online'])
                            <x-ui.badge variant="warning">{{ __('Sin datos recientes') }}</x-ui.badge>
                        @elseif ($tank['alert_low'])
                            <x-ui.badge variant="danger">{{ __('Nivel bajo') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">{{ __('En línea') }}</x-ui.badge>
                        @endif
                    </div>

                    <p class="mt-3 text-4xl font-semibold tabular-nums" style="color: {{ $tank['color'] }}">
                        {{ $pct($tank['fill_pct']) }}
                    </p>

                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                        @if ($tank['minutes_ago'] === null)
                            {{ __('Sin lecturas recientes') }}
                        @else
                            {{ __('Última lectura hace :time', ['time' => \App\Support\Duration::humanShort($tank['minutes_ago'] * 60)]) }}
                        @endif
                    </p>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
