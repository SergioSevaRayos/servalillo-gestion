<div>
    <x-attendance.tabs />

    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Horas y días trabajados por persona en el periodo elegido. Solo cuentan las jornadas ya cerradas — una jornada abierta no suma hasta fichar la salida.') }}
    </p>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <x-ui.select name="period" wire:model.live="period" class="sm:max-w-[10rem]">
            @foreach (\App\Livewire\Attendance\Totals::PERIODS as $value => $label)
                <option value="{{ $value }}">{{ __($label) }}</option>
            @endforeach
        </x-ui.select>
        <div class="sm:max-w-[10rem]">
            <x-ui.date-input name="anchor" wire:model.live="anchor" />
        </div>
        <span class="text-sm text-slate-500 dark:text-slate-400">
            {{ \Illuminate\Support\Carbon::parse($this->range['from'])->format('d/m/Y') }}
            – {{ \Illuminate\Support\Carbon::parse($this->range['to'])->format('d/m/Y') }}
        </span>
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Persona') }}</th>
                <th class="text-right">{{ __('Horas (decimal)') }}</th>
                <th class="text-right">{{ __('Horas') }}</th>
                <th class="text-right">{{ __('Días') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->rows as $row)
                <tr wire:key="totals-row-{{ $row['user_id'] }}">
                    <td data-label="{{ __('Persona') }}">{{ $row['name'] }}</td>
                    <td data-label="{{ __('Horas (decimal)') }}" class="text-right tabular-nums">{{ \App\Support\Duration::decimalHours($row['seconds']) }}</td>
                    <td data-label="{{ __('Horas') }}" class="text-right">{{ \App\Support\Duration::humanShort($row['seconds']) }}</td>
                    <td data-label="{{ __('Días') }}" class="text-right tabular-nums">{{ $row['days'] }}</td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <x-ui.button variant="ghost" size="sm" wire:click="viewDetail({{ $row['user_id'] }})">
                            {{ __('Ver detalle') }}
                        </x-ui.button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <x-ui.empty-state title="{{ __('Sin personas que fichen') }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <x-modal name="attendance-totals-detail" max-width="lg">
        <div class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ $this->detailUser?->name }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ \Illuminate\Support\Carbon::parse($this->range['from'])->format('d/m/Y') }}
                – {{ \Illuminate\Support\Carbon::parse($this->range['to'])->format('d/m/Y') }}
            </p>

            <div class="mt-4 max-h-[55vh] overflow-y-auto themed-scrollbar">
                <x-ui.table :padded="false">
                    <thead>
                        <tr>
                            <th>{{ __('Fecha') }}</th>
                            <th>{{ __('Entrada') }}</th>
                            <th>{{ __('Salida') }}</th>
                            <th class="text-right">{{ __('Horas') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->detailRows as $row)
                            <tr wire:key="detail-{{ $row['date'] }}">
                                <td data-label="{{ __('Fecha') }}">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                                <td data-label="{{ __('Entrada') }}">{{ $row['in_at'] ? \Illuminate\Support\Carbon::parse($row['in_at'])->format('H:i') : '—' }}</td>
                                <td data-label="{{ __('Salida') }}">
                                    @if ($row['open'])
                                        <x-ui.badge variant="warning">{{ __('Jornada abierta — no cuenta') }}</x-ui.badge>
                                    @else
                                        {{ $row['out_at'] ? \Illuminate\Support\Carbon::parse($row['out_at'])->format('H:i') : '—' }}
                                    @endif
                                </td>
                                <td data-label="{{ __('Horas') }}" class="text-right">
                                    {{ $row['seconds'] !== null ? \App\Support\Duration::humanShort($row['seconds']) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-ui.empty-state title="{{ __('Sin fichajes en este periodo') }}" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>

            <div class="mt-6 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
    </x-modal>
</div>
