@php
    $eventMeta = [
        'created' => ['label' => __('Creado'), 'variant' => 'success'],
        'updated' => ['label' => __('Modificado'), 'variant' => 'warning'],
        'deleted' => ['label' => __('Eliminado'), 'variant' => 'danger'],
        'restored' => ['label' => __('Restaurado'), 'variant' => 'primary'],
    ];
    $modelLabel = fn (?string $class) => $class ? (array_search($class, $auditModels, true) ?: class_basename($class)) : '—';
    // De "class_basename" (p. ej. "RouteStop") a la etiqueta en español del mapa de Audits.
    $modelEs = fn (string $base) => collect($auditModels)->search(fn ($fqcn) => class_basename($fqcn) === $base) ?: $base;
    $bytes = function (int $n) {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($n < 1024) {
                return round($n, $unit === 'B' ? 0 : 1).' '.$unit;
            }
            $n /= 1024;
        }
        return round($n, 1).' TB';
    };
    $alertMeta = [
        'danger' => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
        'info' => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
    ];
@endphp

<div>
    <x-maintenance.tabs />

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Salud del sistema, soporte y auditoría') }}</p>
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-soft-sm dark:border-slate-700 dark:bg-slate-800">
            @foreach (['7d' => __('7 días'), '30d' => __('30 días'), '90d' => __('90 días')] as $key => $label)
                <button type="button" wire:click="setRange('{{ $key }}')" @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-primary-600 text-white shadow-soft-sm' => $range === $key,
                    'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' => $range !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Avisos accionables --}}
    @if (count($alerts))
        <div class="mb-5 space-y-2">
            @foreach ($alerts as $alert)
                <a href="{{ $alert['url'] }}" wire:navigate
                    class="flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm transition hover:brightness-[0.98] {{ $alertMeta[$alert['level']] ?? $alertMeta['info'] }}">
                    <span>
                        <span class="font-semibold">{{ $alert['title'] }}</span>
                        <span class="mt-0.5 block text-xs opacity-90">{{ $alert['detail'] }}</span>
                    </span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 h-4 w-4 shrink-0 opacity-70">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                    </svg>
                </a>
            @endforeach
        </div>
    @endif

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
        <x-ui.stat-card :label="__('Incidencias abiertas')" :value="$stats['kpis']['open_tickets']"
            :trend="$stats['kpis']['waiting_support'] ? __(':n sin responder', ['n' => $stats['kpis']['waiting_support']]) : null"
            trend-direction="down" />
        <x-ui.stat-card :label="__('Resueltas (periodo)')" :value="$stats['kpis']['resolved_period']" />
        <x-ui.stat-card :label="__('Errores (periodo)')" :value="$stats['kpis']['errors_period']"
            :trend="$stats['kpis']['errors_24h'] ? __(':n en 24 h', ['n' => $stats['kpis']['errors_24h']]) : null"
            trend-direction="down" />
        <x-ui.stat-card :label="__('Cambios auditados')" :value="$stats['kpis']['audits_period']" />
        <x-ui.stat-card :label="__('Notificaciones sin leer')" :value="$stats['kpis']['unread']" />
        <x-ui.stat-card :label="__('Peso del log')" :value="$bytes($stats['logs']['total_bytes'])" />
    </div>

    {{--
        Gráficos: bloque wire:ignore para que el morph de Livewire no toque los <canvas> al
        cambiar de rango. `maintenanceCharts` (resources/js/app.js) los crea una vez y los
        actualiza con el evento `maint-stats-updated` que emite setRange().
    --}}
    <div wire:ignore x-data="maintenanceCharts(@js($chartData))" class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Errores por día') }}</p>
            <div class="h-56"><canvas x-ref="errors"></canvas></div>
        </x-ui.card>
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Incidencias: abiertas vs resueltas') }}</p>
            <div class="h-56"><canvas x-ref="tickets"></canvas></div>
        </x-ui.card>
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Actividad de auditoría por día') }}</p>
            <div class="h-56"><canvas x-ref="audits"></canvas></div>
        </x-ui.card>
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Incidencias por categoría') }}</p>
            <div class="h-56"><canvas x-ref="category"></canvas></div>
        </x-ui.card>
    </div>

    {{-- Top listas --}}
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-ui.card>
            <p class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Excepciones más frecuentes') }}</p>
            @forelse ($stats['errors_by_class'] as $row)
                @php $max = $stats['errors_by_class'][0]['count'] ?: 1; @endphp
                <div class="flex items-center gap-3 py-1.5 text-sm">
                    <span class="w-40 shrink-0 truncate font-mono text-xs text-slate-700 dark:text-slate-200">{{ $row['label'] }}</span>
                    <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <span class="block h-full rounded-full bg-rose-400" style="width: {{ round($row['count'] / $max * 100) }}%"></span>
                    </span>
                    <span class="w-8 shrink-0 text-right text-xs text-slate-500">{{ $row['count'] }}</span>
                </div>
            @empty
                <p class="py-4 text-center text-sm text-slate-400">{{ __('Sin errores en el periodo.') }}</p>
            @endforelse
        </x-ui.card>
        <x-ui.card>
            <p class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Modelos más modificados') }}</p>
            @forelse ($stats['audits_by_model'] as $row)
                @php $max = $stats['audits_by_model'][0]['count'] ?: 1; @endphp
                <div class="flex items-center gap-3 py-1.5 text-sm">
                    <span class="w-40 shrink-0 truncate text-slate-700 dark:text-slate-200">{{ $modelEs($row['label']) }}</span>
                    <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <span class="block h-full rounded-full bg-primary-400" style="width: {{ round($row['count'] / $max * 100) }}%"></span>
                    </span>
                    <span class="w-8 shrink-0 text-right text-xs text-slate-500">{{ $row['count'] }}</span>
                </div>
            @empty
                <p class="py-4 text-center text-sm text-slate-400">{{ __('Sin actividad en el periodo.') }}</p>
            @endforelse
        </x-ui.card>
    </div>

    {{-- Accesos directos a los últimos registros --}}
    <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card>
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('Incidencias de soporte') }}</h2>
                <a href="{{ route('maintenance.support') }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Ver todas') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($recentTickets as $ticket)
                    <li wire:key="ov-ticket-{{ $ticket->id }}" class="py-2.5">
                        <a href="{{ route('maintenance.support', ['ticket' => $ticket->id]) }}" wire:navigate class="block">
                            <div class="flex items-start justify-between gap-2">
                                <span class="line-clamp-1 text-sm font-medium text-slate-800 dark:text-slate-100">{{ $ticket->subject }}</span>
                                <x-ui.badge :variant="$ticket->status->badgeVariant()">{{ $ticket->status->label() }}</x-ui.badge>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ $ticket->creator?->name ?? '—' }} · {{ $ticket->category->label() }} ·
                                {{ ($ticket->last_reply_at ?? $ticket->created_at)->diffForHumans() }}
                            </p>
                        </a>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-400">{{ __('Sin incidencias abiertas.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('Últimos errores') }}</h2>
                <a href="{{ route('maintenance.errors') }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Ver todos') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($recentErrors as $error)
                    <li wire:key="ov-error-{{ $error->id }}" class="py-2.5">
                        <div class="flex items-start justify-between gap-2">
                            <span class="font-mono text-xs font-medium text-rose-600 dark:text-rose-400">{{ class_basename($error->exception_class) ?: 'Error' }}</span>
                            <span class="shrink-0 text-xs text-slate-400">{{ $error->occurred_at->diffForHumans() }}</span>
                        </div>
                        <p class="mt-0.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($error->message, 120) }}</p>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-400">{{ __('Sin errores registrados.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('Actividad reciente') }}</h2>
                <a href="{{ route('maintenance.audits') }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Ver auditoría') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($recentAudits as $audit)
                    <li wire:key="ov-audit-{{ $audit->id }}" class="flex items-center justify-between gap-2 py-2.5 text-sm">
                        <span class="flex items-center gap-2">
                            <x-ui.badge :variant="$eventMeta[$audit->event]['variant'] ?? 'neutral'">{{ $eventMeta[$audit->event]['label'] ?? $audit->event }}</x-ui.badge>
                            <span class="text-slate-700 dark:text-slate-200">{{ $modelLabel($audit->auditable_type) }}</span>
                            <span class="text-xs text-slate-400">#{{ $audit->auditable_id }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-slate-400">{{ $audit->user?->name ?? __('Sistema') }} · {{ $audit->created_at->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-400">{{ __('Sin actividad registrada.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('Ficheros de log') }}</h2>
                <a href="{{ route('maintenance.logs') }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Abrir visor') }}</a>
            </div>
            <div class="mt-3 flex items-center justify-between text-sm">
                <span class="text-slate-500 dark:text-slate-400">{{ trans_choice(':count fichero|:count ficheros', $stats['logs']['count'], ['count' => $stats['logs']['count']]) }}</span>
                <span class="text-slate-500 dark:text-slate-400">{{ __('Total') }}: <span class="font-medium text-slate-800 dark:text-slate-100">{{ $bytes($stats['logs']['total_bytes']) }}</span></span>
            </div>
            @if ($stats['logs']['biggest'])
                <p class="mt-2 text-xs text-slate-400">
                    {{ __('Mayor') }}:
                    <a href="{{ route('maintenance.logs', ['file' => $stats['logs']['biggest']['name']]) }}" wire:navigate class="font-mono text-slate-600 hover:text-primary-600 dark:text-slate-300">{{ $stats['logs']['biggest']['name'] }}</a>
                    ({{ $bytes($stats['logs']['biggest']['bytes']) }})
                </p>
            @endif
        </x-ui.card>
    </div>
</div>
