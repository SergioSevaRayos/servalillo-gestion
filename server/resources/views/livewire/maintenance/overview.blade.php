@php
    $eventMeta = [
        'created' => ['label' => __('Creado'), 'variant' => 'success'],
        'updated' => ['label' => __('Modificado'), 'variant' => 'warning'],
        'deleted' => ['label' => __('Eliminado'), 'variant' => 'danger'],
        'restored' => ['label' => __('Restaurado'), 'variant' => 'primary'],
    ];
    $modelLabel = fn (?string $class) => $class ? (array_search($class, $auditModels, true) ?: class_basename($class)) : '—';
    $bytes = function (int $n) {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($n < 1024) {
                return round($n, $unit === 'B' ? 0 : 1).' '.$unit;
            }
            $n /= 1024;
        }
        return round($n, 1).' TB';
    };
@endphp

<div>
    <x-maintenance.tabs />

    {{-- KPIs de mantenimiento --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4 xl:grid-cols-5">
        <x-ui.stat-card :label="__('Incidencias abiertas')" :value="$kpis['open_tickets']"
            :trend="$kpis['in_progress'] ? __(':n en curso', ['n' => $kpis['in_progress']]) : null"
            trend-direction="up" />
        <x-ui.stat-card :label="__('Errores (7 días)')" :value="$kpis['errors_week']"
            :trend-direction="$kpis['errors_week'] > 0 ? 'down' : 'up'"
            :trend="$kpis['errors_week'] > 0 ? __('revisar') : null" />
        <x-ui.stat-card :label="__('Cambios auditados hoy')" :value="$kpis['audits_today']" />
        <x-ui.stat-card :label="__('Notificaciones sin leer')" :value="$kpis['unread']" />
        <x-ui.stat-card :label="__('Ficheros de log')" :value="$logFiles->count()" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Incidencias de soporte --}}
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

        {{-- Últimos errores --}}
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

        {{-- Actividad reciente (auditoría) --}}
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
                        <span class="shrink-0 text-xs text-slate-400">
                            {{ $audit->user?->name ?? __('Sistema') }} · {{ $audit->created_at->diffForHumans() }}
                        </span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-400">{{ __('Sin actividad registrada.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        {{-- Ficheros de log --}}
        <x-ui.card>
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('Ficheros de log') }}</h2>
                <a href="{{ route('maintenance.logs') }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Abrir visor') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($logFiles as $log)
                    <li wire:key="ov-log-{{ $log['name'] }}" class="flex items-center justify-between gap-2 py-2.5 text-sm">
                        <a href="{{ route('maintenance.logs', ['file' => $log['name']]) }}" wire:navigate class="font-mono text-xs text-slate-700 hover:text-primary-600 dark:text-slate-200">{{ $log['name'] }}</a>
                        <span class="shrink-0 text-xs text-slate-400">{{ $bytes($log['size']) }} · {{ $log['modified']->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-400">{{ __('No hay ficheros de log.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</div>
