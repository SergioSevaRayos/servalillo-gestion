<div>
    <x-maintenance.tabs />

    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Móviles con la APK de tracking instalada. Cada uno se enrola solo; aquí le asignas el chofer, revocas su acceso o lo desactivas.') }}
    </p>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Chofer') }}</th>
                <th>{{ __('Identificador') }}</th>
                <th>{{ __('Plataforma') }}</th>
                <th>{{ __('Versión') }}</th>
                <th>{{ __('Última señal') }}</th>
                <th class="text-right">{{ __('Posiciones') }}</th>
                <th>{{ __('Estado') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->devices as $device)
                <tr wire:key="device-{{ $device->id }}">
                    <td data-label="{{ __('Chofer') }}">
                        <select
                            wire:key="device-driver-{{ $device->id }}-{{ $device->driver_id ?? 'none' }}"
                            wire:change="assign({{ $device->id }}, $event.target.value)"
                            class="rounded-lg border-slate-300 text-sm shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                        >
                            <option value="">{{ __('— Sin asignar —') }}</option>
                            @foreach ($this->drivers as $driver)
                                <option value="{{ $driver->id }}" @selected($device->driver_id === $driver->id)>{{ $driver->user?->name }}</option>
                            @endforeach
                        </select>
                        @unless ($device->driver_id)
                            <x-ui.badge variant="warning" class="ml-2">{{ __('Sin asignar') }}</x-ui.badge>
                        @endunless
                    </td>
                    <td data-label="{{ __('Identificador') }}" class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($device->install_identifier, 28) }}</td>
                    <td data-label="{{ __('Plataforma') }}" class="capitalize text-slate-600 dark:text-slate-300">{{ $device->platform }}</td>
                    <td data-label="{{ __('Versión') }}" class="tabular-nums text-slate-600 dark:text-slate-300">{{ $device->app_version ?: '—' }}</td>
                    <td data-label="{{ __('Última señal') }}" class="whitespace-nowrap text-slate-500 dark:text-slate-400">
                        @if ($device->last_seen_at)
                            <span title="{{ $device->last_seen_at->format('d/m/Y H:i') }}">{{ $device->last_seen_at->diffForHumans() }}</span>
                        @else
                            <span class="text-amber-600 dark:text-amber-400">{{ __('nunca') }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Posiciones') }}" class="text-right tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($device->positions_count, 0, ',', '.') }}</td>
                    <td data-label="{{ __('Estado') }}">
                        <x-ui.badge :variant="$device->is_active ? 'success' : 'neutral'">
                            {{ $device->is_active ? __('Activo') : __('Desactivado') }}
                        </x-ui.badge>
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="ghost" size="sm" wire:click="toggleActive({{ $device->id }})">
                                {{ $device->is_active ? __('Desactivar') : __('Reactivar') }}
                            </x-ui.button>
                            <x-ui.button
                                variant="ghost" size="sm"
                                wire:click="revoke({{ $device->id }})"
                                wire:confirm="{{ __('¿Revocar el acceso de este dispositivo? La APK tendrá que volver a enrolarse.') }}"
                            >{{ __('Revocar') }}</x-ui.button>
                            <x-ui.button
                                variant="ghost" size="sm"
                                wire:click="delete({{ $device->id }})"
                                wire:confirm="{{ __('¿Eliminar el dispositivo y todas sus posiciones GPS? No se puede deshacer.') }}"
                                class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                            >{{ __('Eliminar') }}</x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="py-10 text-center text-sm text-slate-400">
                        {{ __('Ningún dispositivo enrolado todavía.') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
