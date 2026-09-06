@php
    use App\Enums\RouteStopStatus;
    $route = $this->route;
@endphp

{{-- Web operativa del chofer: siempre .surface, nunca .glass (uso al aire libre, alto contraste). --}}
<div class="mx-auto max-w-2xl">
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Mi ruta de hoy') }}</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ now()->isoFormat('dddd, D [de] MMMM') }}</p>

    @if (! $route)
        <x-ui.card class="mt-6">
            <x-ui.empty-state
                title="{{ __('No tienes ninguna ruta asignada para hoy') }}"
                description="{{ __('Cuando el equipo de oficina te asigne una ruta, aparecerá aquí.') }}"
            />
        </x-ui.card>
    @else
        @php
            $done = $route->stops->whereIn('status', [RouteStopStatus::Completed, RouteStopStatus::Failed, RouteStopStatus::Skipped])->count();
            $total = $route->stops->count();
        @endphp

        {{-- Resumen de la ruta --}}
        <x-ui.card class="mt-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $route->truck->code }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $route->truck->plate }} · {{ $route->name }}</p>
                </div>
                <x-ui.badge :variant="$route->status->badgeVariant()">{{ $route->status->label() }}</x-ui.badge>
            </div>

            <div class="mt-4">
                <div class="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
                    <span>{{ __(':done de :total paradas cerradas', ['done' => $done, 'total' => $total]) }}</span>
                    @if ($this->pendingCount > 0)<span>{{ __(':n pendientes', ['n' => $this->pendingCount]) }}</span>@endif
                </div>
                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full bg-primary-500" style="width: {{ $total > 0 ? round($done / $total * 100) : 0 }}%"></div>
                </div>
            </div>

            {{-- Cisterna: litros cargados, entregados y lo que debería quedar --}}
            @if ($this->tank['has'])
                @php
                    $tk = $this->tank;
                    $usedPct = $tk['loaded'] > 0 ? min(100, round($tk['delivered'] / $tk['loaded'] * 100)) : 0;
                @endphp
                <div class="mt-4 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ __('Cisterna') }}</span>
                        <span class="text-slate-500 dark:text-slate-400">{{ __('cargado :n L', ['n' => number_format($tk['loaded'], 0, ',', '.')]) }}</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                        <div class="h-full rounded-full bg-primary-500" style="width: {{ $usedPct }}%"></div>
                    </div>
                    <div class="mt-2 flex justify-between text-sm">
                        <span class="text-slate-500 dark:text-slate-400">{{ __('Entregado: :n L', ['n' => number_format($tk['delivered'], 0, ',', '.')]) }}</span>
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ __('Debería quedar: :n L', ['n' => number_format(max(0, $tk['theoretical']), 0, ',', '.')]) }}</span>
                    </div>
                    @if ($route->tank_reconciliation_note)
                        <p class="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            <span class="font-medium">{{ __('Ajuste registrado:') }}</span> {{ $route->tank_reconciliation_note }}
                        </p>
                    @endif
                </div>
            @endif

            {{-- Control de jornada --}}
            <div class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                @if (! $this->started)
                    <x-ui.button class="w-full justify-center" size="lg" wire:click="openStartDay">
                        {{ __('Empezar jornada') }}
                    </x-ui.button>
                    <p class="mt-2 text-center text-xs text-slate-400">{{ __('Registra el contador de inicio para poder operar las paradas.') }}</p>
                @elseif (! $this->finished)
                    @php $startReading = $route->odometerReadings->firstWhere('kind', \App\Enums\OdometerKind::Start)?->value; @endphp
                    @if ($startReading !== null)
                        <p class="mb-3 text-center text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Contador de inicio: :km km', ['km' => number_format($startReading, 0, ',', '.')]) }}
                        </p>
                    @endif
                    <x-ui.button variant="secondary" class="w-full justify-center" size="lg" wire:click="openEndDay">
                        {{ __('Terminar jornada') }}
                    </x-ui.button>
                @else
                    @php
                        $start = $route->odometerReadings->firstWhere('kind', \App\Enums\OdometerKind::Start)?->value;
                        $end = $route->odometerReadings->firstWhere('kind', \App\Enums\OdometerKind::End)?->value;
                    @endphp
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div><p class="text-xs text-slate-400">{{ __('Contador inicio') }}</p><p class="font-semibold text-slate-800 dark:text-slate-100">{{ $start !== null ? number_format($start, 0, ',', '.') : '—' }}</p></div>
                        <div><p class="text-xs text-slate-400">{{ __('Contador fin') }}</p><p class="font-semibold text-slate-800 dark:text-slate-100">{{ $end !== null ? number_format($end, 0, ',', '.') : '—' }}</p></div>
                        <div><p class="text-xs text-slate-400">{{ __('Km jornada') }}</p><p class="font-semibold text-slate-800 dark:text-slate-100">{{ ($start !== null && $end !== null) ? number_format($end - $start, 0, ',', '.') : '—' }}</p></div>
                    </div>
                    @if ($route->tank_loaded_liters !== null)
                        <div class="mt-3 grid grid-cols-3 gap-3 text-center">
                            <div><p class="text-xs text-slate-400">{{ __('Cargado') }}</p><p class="font-semibold text-slate-800 dark:text-slate-100">{{ number_format($route->tank_loaded_liters, 0, ',', '.') }} L</p></div>
                            <div><p class="text-xs text-slate-400">{{ __('Entregado') }}</p><p class="font-semibold text-slate-800 dark:text-slate-100">{{ number_format($route->deliveredLiters(), 0, ',', '.') }} L</p></div>
                            <div><p class="text-xs text-slate-400">{{ __('Restante') }}</p><p class="font-semibold text-slate-800 dark:text-slate-100">{{ $route->tank_remaining_liters !== null ? number_format($route->tank_remaining_liters, 0, ',', '.').' L' : '—' }}</p></div>
                        </div>
                    @endif
                    <p class="mt-3 text-center text-sm font-medium text-emerald-700 dark:text-emerald-400">{{ __('Jornada finalizada') }}</p>
                @endif
            </div>
        </x-ui.card>

        {{-- Paradas --}}
        <div class="mt-4 space-y-3">
            @forelse ($route->stops as $stop)
                <x-chofer.stop-card
                    :stop="$stop"
                    :index="$loop->iteration"
                    :disabled="! $this->started || $this->finished"
                />
            @empty
                <x-ui.card><x-ui.empty-state title="{{ __('Esta ruta no tiene paradas') }}" /></x-ui.card>
            @endforelse
        </div>
    @endif

    {{-- Modal: empezar jornada --}}
    <x-modal name="start-day" max-width="sm">
        <form wire:submit="startDay" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Empezar jornada') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Ajusta la lectura del cuentakilómetros del camión ahora mismo.') }}</p>
            <div class="mt-4">
                <p class="mb-2 text-center text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Contador (km)') }}</p>
                <x-ui.digit-wheel wire:model="odometer" :value="$route?->truck?->odometer ?? 0" sync-on="start-day" />
            </div>
            <div class="mt-4">
                <x-ui.input name="tankLoaded" type="number" inputmode="numeric"
                    label="{{ __('Litros cargados en la cisterna') }}" wire:model="tankLoaded"
                    help="{{ $route?->truck?->capacity_liters ? __('Capacidad del camión: :n L', ['n' => number_format($route->truck->capacity_liters, 0, ',', '.')]) : null }}" />
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Empezar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>

    {{-- Modal: terminar jornada --}}
    <x-modal name="end-day" max-width="sm">
        <form wire:submit="endDay" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Terminar jornada') }}</h3>
            @if ($this->pendingCount > 0)
                <p class="mt-1 text-sm text-amber-600 dark:text-amber-400">{{ __('Quedan :n paradas sin cerrar. Aun así puedes terminar la jornada.', ['n' => $this->pendingCount]) }}</p>
            @endif
            <div class="mt-4">
                <p class="mb-2 text-center text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Contador (km)') }}</p>
                <x-ui.digit-wheel wire:model="odometer" :value="$route?->truck?->odometer ?? 0" sync-on="end-day" />
            </div>

            {{-- Cisterna: comprobación de que lo medido cuadra con cargado − entregado --}}
            <div class="mt-4">
                @php $theo = $route?->tankTheoreticalRemaining(); $disc = $this->endDayDiscrepancy(); @endphp
                @if ($theo !== null)
                    <p class="mb-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Cargado :c L · entregado :e L · debería quedar :t L', [
                            'c' => number_format($route->tank_loaded_liters, 0, ',', '.'),
                            'e' => number_format($this->tank['delivered'], 0, ',', '.'),
                            't' => number_format(max(0, $theo), 0, ',', '.'),
                        ]) }}
                    </p>
                @endif
                <x-ui.input name="tankRemaining" type="number" inputmode="numeric"
                    label="{{ __('Litros que quedan en la cisterna') }}" wire:model.live.debounce.500ms="tankRemaining" />

                @if ($disc !== null && abs($disc) > (int) config('servalillo.tank_tolerance_liters', 0))
                    <div class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <p class="font-medium">
                            {{ $disc > 0
                                ? __('Los datos no coinciden: faltan :n L.', ['n' => number_format($disc, 0, ',', '.')])
                                : __('Los datos no coinciden: sobran :n L.', ['n' => number_format(abs($disc), 0, ',', '.')]) }}
                        </p>
                        <div class="mt-2">
                            <x-ui.textarea name="tankNote" wire:model="tankNote" rows="2"
                                label="{{ __('Motivo del ajuste') }}"
                                placeholder="{{ __('Ej: se perdieron 4 L al soltarse la manguera y derramarse.') }}" />
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Terminar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>

    {{-- Modal: acción sobre una parada --}}
    <x-modal name="stop-action" max-width="lg">
        @if ($this->form->stop)
            @php $s = $this->form->stop; @endphp
            <div class="p-6">
                <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ $s->customer_name }}</h3>
                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $s->address ?: '—' }}</p>
                @if ($s->contact_phone)
                    <a href="tel:{{ $s->contact_phone }}" class="mt-1 inline-flex items-center gap-1 text-sm text-primary-600 dark:text-primary-400">
                        {{ $s->contact_name ? $s->contact_name.' · ' : '' }}{{ $s->contact_phone }}
                    </a>
                @endif

                @if ($s->deliveryType && $s->planned_quantity)
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Pedido: :qty L de :type', ['qty' => number_format($s->planned_quantity, 0, ',', '.'), 'type' => $s->deliveryType->name]) }}
                    </p>
                @endif

                {{-- Selector de resultado --}}
                <div class="mt-4 grid grid-cols-3 gap-2">
                    @foreach (['completed' => __('Entregada'), 'failed' => __('Fallida'), 'skipped' => __('Omitida')] as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('form.outcome', '{{ $value }}')"
                            @class([
                                'rounded-lg border px-2 py-2 text-sm font-medium transition-colors',
                                'border-primary-600 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $this->form->outcome === $value,
                                'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $this->form->outcome !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>

                <form wire:submit="saveStop" class="mt-4 space-y-4">
                    @if ($this->form->outcome === 'completed')
                        @php $needsSignature = $this->form->channelRequiresSignature(); @endphp

                        {{-- Cómo se entrega el albarán: primera decisión, condiciona el resto del formulario. --}}
                        @if (! $s->deliveryNote)
                            <div>
                                <x-input-label :value="__('Cómo se entrega el albarán')" />
                                <div class="mt-1 grid grid-cols-2 gap-2">
                                    @foreach (['email' => __('Enviar por email'), 'physical' => __('Entrega en mano')] as $value => $label)
                                        <button
                                            type="button"
                                            wire:click="$set('form.channel', '{{ $value }}')"
                                            @class([
                                                'rounded-lg border px-2 py-2 text-sm font-medium transition-colors',
                                                'border-primary-600 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $this->form->channel === $value,
                                                'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $this->form->channel !== $value,
                                            ])
                                        >{{ $label }}</button>
                                    @endforeach
                                </div>
                                @if (! $needsSignature)
                                    <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                                        {{ __('Se entrega la copia impresa en mano; el cliente firma el papel.') }}
                                    </p>
                                @endif
                            </div>
                        @else
                            <p class="rounded-lg bg-slate-100 p-3 text-sm text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                {{ __('Albarán :n · :estado', ['n' => $s->deliveryNote->number, 'estado' => $s->deliveryNote->status->label()]) }}
                            </p>
                        @endif

                        <x-ui.input name="form.delivered_quantity" type="number" step="any" inputmode="decimal"
                            label="{{ __('Litros entregados') }}" wire:model="form.delivered_quantity" />

                        @if ($s->deliveryType)
                            @foreach ($s->deliveryType->fields() as $field)
                                <div>
                                    @switch($field['type'])
                                        @case('boolean')
                                            <x-ui.checkbox :name="'fd_'.$field['key']" :label="$field['label']" wire:model="form.data.{{ $field['key'] }}" />
                                            @break
                                        @case('select')
                                            <x-ui.select :name="'fd_'.$field['key']" :label="$field['label']" wire:model="form.data.{{ $field['key'] }}" placeholder="{{ __('Selecciona…') }}">
                                                @foreach ($field['options'] ?? [] as $option)
                                                    <option value="{{ $option }}">{{ $option }}</option>
                                                @endforeach
                                            </x-ui.select>
                                            @break
                                        @case('textarea')
                                            <x-ui.textarea :name="'fd_'.$field['key']" :label="$field['label']" wire:model="form.data.{{ $field['key'] }}" />
                                            @break
                                        @case('date')
                                            <x-ui.input :name="'fd_'.$field['key']" :label="$field['label']" type="date" wire:model="form.data.{{ $field['key'] }}" />
                                            @break
                                        @case('number')
                                            <x-ui.input :name="'fd_'.$field['key']" type="number" :label="$field['label'].(!empty($field['unit']) ? ' ('.$field['unit'].')' : '')" wire:model="form.data.{{ $field['key'] }}" />
                                            @break
                                        @default
                                            <x-ui.input :name="'fd_'.$field['key']" :label="$field['label']" wire:model="form.data.{{ $field['key'] }}" />
                                    @endswitch
                                </div>
                            @endforeach
                        @endif

                        {{-- Campos del albarán, según el canal elegido arriba. --}}
                        @if (! $s->deliveryNote)
                            @if ($needsSignature)
                                <x-ui.input name="form.recipient_email" type="email" label="{{ __('Email del cliente') }}" wire:model="form.recipient_email" />
                                <x-ui.input name="form.signer_name" label="{{ __('Nombre de quien firma') }}" wire:model="form.signer_name" />
                                <x-ui.signature-pad wire:model="form.signature" sync-on="stop-action" />
                            @else
                                <x-ui.input name="form.signer_name" label="{{ __('Recibido por (opcional)') }}" wire:model="form.signer_name" />
                            @endif
                        @endif
                    @else
                        <x-ui.textarea name="form.reason" label="{{ $this->form->outcome === 'failed' ? __('Motivo del fallo') : __('Motivo para omitir') }}" wire:model="form.reason" rows="3" />
                    @endif

                    <div class="flex items-center justify-between gap-3 pt-2">
                        @if ($s->status !== RouteStopStatus::Pending)
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="reopenStop"
                                class="!text-slate-600 dark:!text-slate-300">{{ __('Reabrir parada') }}</x-ui.button>
                        @else
                            <span></span>
                        @endif
                        <div class="flex gap-3">
                            <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                            <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
                        </div>
                    </div>
                </form>
            </div>
        @endif
    </x-modal>
</div>
