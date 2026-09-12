<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('Fichajes') }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Registro de jornada de administrador y chofers. Corrige olvidos con motivo — todo queda anotado.') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" wire:click="exportXml">{{ __('Exportar (formato legal)') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="exportPdf">{{ __('Exportar PDF') }}</x-ui.button>
            <x-ui.button wire:click="openCreate">{{ __('Registrar fichaje olvidado') }}</x-ui.button>
        </div>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar por persona…') }}"
            class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
        />
        <input
            type="month"
            wire:model.live="month"
            class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm"
        />
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Persona') }}</th>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Entrada') }}</th>
                <th>{{ __('Salida') }}</th>
                <th class="text-right">{{ __('Horas') }}</th>
                <th>{{ __('Zona') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->attendances as $attendance)
                <tr wire:key="attendance-row-{{ $attendance->id }}">
                    <td data-label="{{ __('Persona') }}">{{ $attendance->user->name }}</td>
                    <td data-label="{{ __('Fecha') }}">{{ $attendance->date->format('d/m/Y') }}</td>
                    <td data-label="{{ __('Entrada') }}">{{ $attendance->in_at?->format('H:i') ?? '—' }}</td>
                    <td data-label="{{ __('Salida') }}">
                        @if ($attendance->in_at && ! $attendance->out_at)
                            <x-ui.badge variant="warning">{{ __('Jornada abierta') }}</x-ui.badge>
                        @else
                            {{ $attendance->out_at?->format('H:i') ?? '—' }}
                        @endif
                    </td>
                    <td data-label="{{ __('Horas') }}" class="text-right">
                        {{ $attendance->total_seconds !== null ? \App\Support\Duration::humanShort($attendance->total_seconds) : '—' }}
                    </td>
                    <td data-label="{{ __('Zona') }}">
                        @if ($attendance->in_out_of_bounds || $attendance->out_out_of_bounds)
                            <x-ui.badge variant="warning">{{ __('Fuera de zona') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="neutral">{{ __('OK') }}</x-ui.badge>
                        @endif
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($attendance->in_latitude !== null || $attendance->out_latitude !== null)
                                <x-ui.button variant="ghost" size="sm" wire:click="viewLocation({{ $attendance->id }})">
                                    {{ __('Ver ubicación') }}
                                </x-ui.button>
                            @endif
                            @if ($attendance->corrections()->exists())
                                <x-ui.button variant="ghost" size="sm" wire:click="viewCorrections({{ $attendance->id }})">
                                    {{ __('Ver correcciones') }}
                                </x-ui.button>
                            @endif
                            @if ($attendance->in_at && ! $attendance->out_at)
                                <x-ui.button variant="ghost" size="sm" wire:click="openCloseNow({{ $attendance->id }})">
                                    {{ __('Cerrar ahora') }}
                                </x-ui.button>
                            @elseif ($attendance->out_at)
                                <x-ui.button variant="ghost" size="sm" wire:click="openReopen({{ $attendance->id }})">
                                    {{ __('Reabrir') }}
                                </x-ui.button>
                            @endif
                            <x-ui.button variant="ghost" size="sm" wire:click="openCorrect({{ $attendance->id }})">
                                {{ __('Corregir') }}
                            </x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state title="{{ __('Sin fichajes este mes') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $this->attendances->links() }}</div>

    <div class="mt-8 border-t border-slate-200 pt-6 dark:border-slate-700">
        <h3 class="text-base font-medium text-slate-800 dark:text-slate-100">{{ __('Dónde puede fichar cada persona') }}</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Por defecto se ficha desde la base. Para dar a alguien su propia zona (p. ej. un chofer que aparca el camión en otro sitio), edítalo desde su ficha:') }}
            <a href="{{ route('drivers.index') }}" wire:navigate class="text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Chofers') }}</a>
            {{ __('o') }}
            <a href="{{ route('users.index') }}" wire:navigate class="text-primary-600 hover:text-primary-800 dark:text-primary-400">{{ __('Usuarios') }}</a>.
        </p>

        <x-ui.table class="mt-3">
            <thead>
                <tr>
                    <th>{{ __('Persona') }}</th>
                    <th>{{ __('Modo') }}</th>
                    <th>{{ __('Radio') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->eligibleUsers as $user)
                    <tr wire:key="location-{{ $user->id }}">
                        <td data-label="{{ __('Persona') }}">{{ $user->name }}</td>
                        <td data-label="{{ __('Modo') }}">
                            <x-ui.badge :variant="$user->attendance_mode === 'remote' ? 'primary' : 'neutral'">
                                {{ $user->attendance_mode === 'remote' ? __('Remoto') : __('Base') }}
                            </x-ui.badge>
                        </td>
                        <td data-label="{{ __('Radio') }}">
                            @if ($user->attendance_radius_meters)
                                {{ $user->attendance_radius_meters }} m
                            @else
                                {{ config('servalillo.attendance.default_radius_meters') }} m ({{ __('por defecto') }})
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    </div>

    <x-modal name="attendance-correct" max-width="md">
        <form wire:submit="saveCorrect" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Corregir fichaje') }}</h3>

            <div class="mt-6 space-y-4">
                <x-ui.input type="datetime-local" name="correct_in_at" label="{{ __('Entrada') }}" wire:model="correct_in_at" />
                <x-ui.input type="datetime-local" name="correct_out_at" label="{{ __('Salida') }}" wire:model="correct_out_at" />
                <x-ui.textarea name="correct_reason" label="{{ __('Motivo de la corrección') }}" wire:model="correct_reason" :rows="3" placeholder="{{ __('Obligatorio: por qué se corrige este fichaje') }}" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar corrección') }}</x-ui.button>
            </div>
        </form>
    </x-modal>

    <x-modal name="attendance-create" max-width="md">
        <form wire:submit="saveCreate" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Registrar fichaje olvidado') }}</h3>

            <div class="mt-6 space-y-4">
                <x-ui.select name="create_user_id" label="{{ __('Persona') }}" wire:model="create_user_id" placeholder="{{ __('Selecciona una persona') }}">
                    @foreach ($this->eligibleUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.date-input name="create_date" label="{{ __('Fecha') }}" wire:model="create_date" />
                <x-ui.input type="datetime-local" name="create_in_at" label="{{ __('Entrada') }}" wire:model="create_in_at" />
                <x-ui.input type="datetime-local" name="create_out_at" label="{{ __('Salida') }}" wire:model="create_out_at" />
                <x-ui.textarea name="create_reason" label="{{ __('Motivo') }}" wire:model="create_reason" :rows="3" placeholder="{{ __('Obligatorio: por qué se registra a mano') }}" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Registrar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>

    <x-modal name="attendance-corrections" max-width="lg">
        <div class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Historial de correcciones') }}</h3>

            <div class="mt-4 max-h-[55vh] space-y-4 overflow-y-auto themed-scrollbar px-1 -mx-1">
                @forelse ($this->viewingCorrections as $correction)
                    <div class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                        <p class="font-medium text-slate-700 dark:text-slate-200">
                            {{ $correction->correctedBy?->name ?? __('Sistema') }} · {{ $correction->created_at->format('d/m/Y H:i') }}
                        </p>
                        <p class="mt-1 text-slate-600 dark:text-slate-300">{{ $correction->reason }}</p>
                        <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                            {{ __('Entrada') }}: {{ $correction->old_values['in_at'] ?? '—' }} → {{ $correction->new_values['in_at'] ?? '—' }}
                            · {{ __('Salida') }}: {{ $correction->old_values['out_at'] ?? '—' }} → {{ $correction->new_values['out_at'] ?? '—' }}
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">{{ __('Sin correcciones.') }}</p>
                @endforelse
            </div>

            <div class="mt-6 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
    </x-modal>

    <x-attendance-location-modal />
</div>
