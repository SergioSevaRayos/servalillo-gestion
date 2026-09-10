@php
    $eventMeta = [
        'created' => ['label' => __('Creado'), 'variant' => 'success'],
        'updated' => ['label' => __('Modificado'), 'variant' => 'warning'],
        'deleted' => ['label' => __('Eliminado'), 'variant' => 'danger'],
        'restored' => ['label' => __('Restaurado'), 'variant' => 'primary'],
        'login' => ['label' => __('Inicio de sesión'), 'variant' => 'neutral'],
    ];
    $labelFor = fn (?string $class) => $class ? (array_search($class, $models, true) ?: class_basename($class)) : '—';
@endphp

<div>
    <x-maintenance.tabs />

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar por usuario o modelo…') }}"
            class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:max-w-xs lg:text-sm"
        />
        <select wire:model.live="model" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Todos los modelos') }}</option>
            @foreach ($models as $label => $class)
                <option value="{{ $class }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="event" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Todos los eventos') }}</option>
            <option value="created">{{ __('Creado') }}</option>
            <option value="updated">{{ __('Modificado') }}</option>
            <option value="deleted">{{ __('Eliminado') }}</option>
            <option value="restored">{{ __('Restaurado') }}</option>
            <option value="login">{{ __('Inicio de sesión') }}</option>
        </select>
        <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
        <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
        <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Usuario') }}</th>
                <th>{{ __('Evento') }}</th>
                <th>{{ __('Modelo') }}</th>
                <th>{{ __('Cambios') }}</th>
                <th class="text-right">{{ __('Detalle') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($audits as $audit)
                <tr wire:key="audit-{{ $audit->id }}">
                    <td data-label="{{ __('Fecha') }}" class="whitespace-nowrap text-slate-500 dark:text-slate-400">
                        {{ $audit->created_at->format('d/m/Y H:i') }}
                    </td>
                    <td data-label="{{ __('Usuario') }}">{{ $audit->user?->name ?? __('Sistema') }}</td>
                    <td data-label="{{ __('Evento') }}">
                        <x-ui.badge :variant="$eventMeta[$audit->event]['variant'] ?? 'neutral'">
                            {{ $eventMeta[$audit->event]['label'] ?? $audit->event }}
                        </x-ui.badge>
                    </td>
                    <td data-label="{{ __('Modelo') }}">
                        <span class="font-medium text-slate-800 dark:text-slate-100">{{ $labelFor($audit->auditable_type) }}</span>
                        <span class="text-xs text-slate-400">#{{ $audit->auditable_id }}</span>
                    </td>
                    <td data-label="{{ __('Cambios') }}" class="text-slate-500 dark:text-slate-400">
                        {{ \Illuminate\Support\Str::limit(collect(array_keys($audit->getModified()))->implode(', '), 60) ?: '—' }}
                    </td>
                    <td data-label="{{ __('Detalle') }}" class="text-right">
                        <x-ui.button variant="ghost" size="sm" wire:click="show({{ $audit->id }})">{{ __('Ver') }}</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6"><x-ui.empty-state title="{{ __('Sin registros de auditoría') }}" description="{{ __('Ajusta los filtros o espera a que se produzcan cambios.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $audits->links() }}</div>

    <x-modal name="audit-detail" max-width="2xl">
        @if ($this->selected)
            @php $modified = $this->selected->getModified(); @endphp
            <div class="p-6">
                <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                    {{ $labelFor($this->selected->auditable_type) }} #{{ $this->selected->auditable_id }}
                </h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $eventMeta[$this->selected->event]['label'] ?? $this->selected->event }} ·
                    {{ $this->selected->created_at->format('d/m/Y H:i:s') }} ·
                    {{ $this->selected->user?->name ?? __('Sistema') }}
                </p>

                <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="px-3 py-2">{{ __('Campo') }}</th>
                                <th class="px-3 py-2">{{ __('Antes') }}</th>
                                <th class="px-3 py-2">{{ __('Después') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @forelse ($modified as $field => $values)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-slate-700 dark:text-slate-200">{{ $field }}</td>
                                    <td class="px-3 py-2 text-rose-600 dark:text-rose-400">{{ \Illuminate\Support\Str::limit(json_encode($values['old'] ?? null, JSON_UNESCAPED_UNICODE), 120) }}</td>
                                    <td class="px-3 py-2 text-emerald-700 dark:text-emerald-400">{{ \Illuminate\Support\Str::limit(json_encode($values['new'] ?? null, JSON_UNESCAPED_UNICODE), 120) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-4 text-center text-slate-400">{{ __('Sin cambios de campos registrados.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-2 text-xs text-slate-500 dark:text-slate-400 sm:grid-cols-2">
                    <div><dt class="font-medium text-slate-600 dark:text-slate-300">URL</dt><dd class="break-all">{{ $this->selected->url ?: '—' }}</dd></div>
                    <div><dt class="font-medium text-slate-600 dark:text-slate-300">IP</dt><dd>{{ $this->selected->ip_address ?: '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="font-medium text-slate-600 dark:text-slate-300">User agent</dt><dd class="break-all">{{ $this->selected->user_agent ?: '—' }}</dd></div>
                </dl>

                <div class="mt-6 flex justify-end">
                    <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
