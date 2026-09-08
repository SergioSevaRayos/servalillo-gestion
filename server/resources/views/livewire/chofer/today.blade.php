@php
    use App\Enums\RouteStopStatus;
    use Illuminate\Support\Carbon;
    $route = $this->route;
    $selected = Carbon::parse($this->date);
@endphp

{{-- Web operativa del chofer: siempre .surface, nunca .glass (uso al aire libre, alto contraste).
     wire:poll: refresco automático — lo que cambie oficina aparece solo, sin recargar. --}}
<div class="mx-auto max-w-2xl" wire:poll.15s>
    <div class="flex items-baseline justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Mi ruta') }}</h1>
        <button type="button" wire:click="goToday" @disabled($this->isToday()) @class([
            'text-sm font-semibold transition-colors',
            'text-primary-600 hover:text-primary-800 dark:text-primary-400' => ! $this->isToday(),
            'cursor-default text-slate-300 dark:text-slate-600' => $this->isToday(),
        ])>
            {{ __('Hoy') }}
        </button>
    </div>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $selected->isoFormat('dddd, D [de] MMMM') }}</p>

    {{-- Selector de día tipo carrusel "coverflow" sobre scroll nativo con scroll-snap: el día
         más centrado se ajusta solo (mismo "imán" que el dial de litros). Se mueve arrastrando,
         con la rueda del ratón encima, con las flechas o tocando un día. La lógica está en
         resources/js/app.js (dayCarousel). --}}
    <div class="mt-3 flex items-center justify-center gap-1.5" wire:ignore
        x-data="dayCarousel({ initial: @js($this->date), today: @js(today()->toDateString()) })">
        <button type="button" x-on:click="nudge(-1)" aria-label="{{ __('Día anterior') }}"
            class="day-carousel__arrow">‹</button>

        <div class="day-carousel w-[260px] max-w-full"
            x-ref="scroller"
            x-on:scroll.passive="onScroll()"
            role="group" aria-label="{{ __('Selector de día') }}">
            <div class="day-carousel__track" x-ref="track">
                <div class="day-carousel__spacer" aria-hidden="true"></div>
                <template x-for="d in days()" :key="d.iso">
                    <button type="button"
                        class="day-carousel__item"
                        :data-day="d.iso"
                        :class="{ 'is-today': d.iso === todayIso }"
                        x-on:click="tap(d.iso)">
                        <span class="day-carousel__dow" x-text="LETTERS[d.dow]"></span>
                        <span class="day-carousel__num" x-text="d.day"></span>
                    </button>
                </template>
                <div class="day-carousel__spacer" aria-hidden="true"></div>
            </div>
        </div>

        <button type="button" x-on:click="nudge(1)" aria-label="{{ __('Día siguiente') }}"
            class="day-carousel__arrow">›</button>
    </div>

    @if (! $route)
        <x-ui.card class="mt-6">
            <x-ui.empty-state
                title="{{ $this->isToday() ? __('No tienes ninguna ruta asignada para hoy') : __('No hay ruta para este día') }}"
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

            {{-- Contador de litros: cómo estaba al empezar y por dónde va con cada reparto --}}
            @if ($this->meter['has'])
                @php $mt = $this->meter; @endphp
                <div class="mt-4 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ __('Contador de litros') }}</span>
                        <span class="text-slate-500 dark:text-slate-400">{{ __('repartido: :n L', ['n' => number_format($mt['delivered'], 0, ',', '.')]) }}</span>
                    </div>
                    <div class="mt-2 flex items-end justify-between">
                        <div>
                            <p class="text-xs text-slate-400">{{ __('Al empezar') }}</p>
                            <p class="font-semibold tabular-nums text-slate-600 dark:text-slate-300">{{ number_format($mt['start'], 0, ',', '.') }}</p>
                        </div>
                        <span class="pb-1 text-slate-300 dark:text-slate-600">→</span>
                        <div class="text-right">
                            <p class="text-xs text-slate-400">{{ __('Va por') }}</p>
                            <p class="text-xl font-bold tabular-nums text-primary-600 dark:text-primary-400">{{ number_format($mt['expected'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                    @if ($route->liter_discrepancy_note)
                        <p class="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            <span class="font-medium">{{ __('Ajuste registrado:') }}</span> {{ $route->liter_discrepancy_note }}
                        </p>
                    @endif
                </div>
            @endif

            {{-- Control de jornada --}}
            <div class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                @if (! $this->started)
                    @if ($this->isToday())
                        <x-ui.button class="w-full justify-center" size="lg" wire:click="openStartDay">
                            {{ __('Empezar jornada') }}
                        </x-ui.button>
                        <p class="mt-2 text-center text-xs text-slate-400">{{ __('Anota la lectura del contador para poder operar las paradas.') }}</p>
                    @else
                        <p class="text-center text-sm text-slate-500 dark:text-slate-400">
                            {{ $selected->isFuture() ? __('Ruta planificada. Podrás empezarla ese día.') : __('Esta jornada no llegó a iniciarse.') }}
                        </p>
                    @endif
                @elseif (! $this->finished)
                    <x-ui.button variant="secondary" class="w-full justify-center" size="lg" wire:click="openEndDay">
                        {{ __('Terminar jornada') }}
                    </x-ui.button>
                @else
                    @if ($route->liter_meter_start !== null)
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div><p class="text-xs text-slate-400">{{ __('Contador inicio') }}</p><p class="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{{ number_format($route->liter_meter_start, 0, ',', '.') }}</p></div>
                            <div><p class="text-xs text-slate-400">{{ __('Contador fin') }}</p><p class="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{{ $route->liter_meter_end !== null ? number_format($route->liter_meter_end, 0, ',', '.') : '—' }}</p></div>
                            <div><p class="text-xs text-slate-400">{{ __('Repartido') }}</p><p class="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($route->deliveredLiters(), 0, ',', '.') }} L</p></div>
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
                    :disabled="! $this->operable() || ! $this->started || $this->finished"
                />
            @empty
                <x-ui.card><x-ui.empty-state title="{{ __('Esta ruta no tiene paradas') }}" /></x-ui.card>
            @endforelse
        </div>

        {{-- Reordenar las paradas pendientes por cercanía (la próxima queda fija) --}}
        @if ($this->operable() && ! $this->finished && $this->pendingCount > 1)
            <button type="button" wire:click="optimizeRoute" wire:target="optimizeRoute"
                wire:loading.attr="disabled"
                wire:confirm="{{ __('¿Reorganizar las paradas pendientes por cercanía? Tu próxima parada no cambia.') }}"
                class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 py-3 text-sm font-medium text-slate-500 transition-colors hover:border-primary-400 hover:text-primary-600 disabled:opacity-50 dark:border-slate-700 dark:text-slate-400 dark:hover:border-primary-500 dark:hover:text-primary-300">
                <svg wire:loading.remove wire:target="optimizeRoute" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path fill-rule="evenodd" d="M11.3 1.046a1 1 0 0 1 .7 1.19L10.42 8H15a1 1 0 0 1 .8 1.6l-7 9.333A1 1 0 0 1 7 18.333L8.58 12H4a1 1 0 0 1-.8-1.6l7-9.333a1 1 0 0 1 1.1-.021Z" clip-rule="evenodd" />
                </svg>
                <svg wire:loading wire:target="optimizeRoute" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                </svg>
                {{ __('Organizar mi ruta') }}
            </button>
        @endif

        {{-- Añadir un cliente que ha llamado como parada nueva (al final de la ruta) --}}
        @if ($this->operable() && ! $this->finished)
            <button type="button" wire:click="openAddStop"
                class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 py-3 text-sm font-medium text-slate-500 transition-colors hover:border-primary-400 hover:text-primary-600 dark:border-slate-700 dark:text-slate-400 dark:hover:border-primary-500 dark:hover:text-primary-300">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ __('Añadir cliente (ha llamado)') }}
            </button>
        @endif
    @endif

    {{-- Paradas que reprogramaste para este día y que oficina aún no ha metido en una ruta --}}
    @if ($this->rescheduledForDay->isNotEmpty())
        <x-ui.card class="mt-4">
            <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Reprogramadas para este día') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Pendientes de que oficina las asigne a una ruta.') }}</p>
            <ul class="mt-3 space-y-2">
                @foreach ($this->rescheduledForDay as $stop)
                    <li class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                        <div>
                            <p class="font-medium text-slate-800 dark:text-slate-100">{{ $stop->customer_name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $stop->address ?: '—' }}</p>
                        </div>
                        <x-ui.badge variant="warning">{{ __('sin asignar') }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- Modal: empezar jornada --}}
    <x-modal name="start-day" max-width="sm">
        <form wire:submit="startDay" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Empezar jornada') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Ajusta la lectura que marca el contador de litros del camión ahora mismo.') }}</p>
            <div class="mt-4">
                <p class="mb-2 text-center text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Contador de litros') }}</p>
                <x-ui.digit-wheel wire:model="meterStart" :value="$route?->truck?->liter_meter ?? 0" unit="L" sync-on="start-day" />
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
            {{-- Contador de litros: (fin − inicio) debe cuadrar con lo repartido --}}
            <div class="mt-4">
                @php $disc = $this->endMeterDiscrepancy(); @endphp
                <p class="mb-2 text-center text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Contador de litros ahora') }}</p>
                <x-ui.digit-wheel wire:model="meterEnd" :value="(int) round($route?->literMeterExpected() ?? 0)" unit="L" sync-on="end-day" />

                @if ($route?->liter_meter_start !== null)
                    <p class="mt-1 text-center text-xs text-slate-400">
                        {{ __('Al empezar :s · repartido :r L → debería marcar :e', [
                            's' => number_format($route->liter_meter_start, 0, ',', '.'),
                            'r' => number_format($this->meter['delivered'], 0, ',', '.'),
                            'e' => number_format($route->literMeterExpected(), 0, ',', '.'),
                        ]) }}
                    </p>
                @endif

                @if ($disc !== null && abs($disc) > (int) config('servalillo.liter_meter_tolerance', 0))
                    <div class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <p class="font-medium">
                            {{ $disc > 0
                                ? __('El contador marca :n L más de lo repartido.', ['n' => number_format($disc, 0, ',', '.')])
                                : __('El contador marca :n L menos de lo repartido.', ['n' => number_format(abs($disc), 0, ',', '.')]) }}
                        </p>
                        <div class="mt-2">
                            <x-ui.textarea name="meterNote" wire:model="meterNote" rows="2"
                                label="{{ __('Motivo del ajuste') }}"
                                placeholder="{{ __('Ej: se soltó la manguera y se derramaron 4 L por el suelo.') }}" />
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

    {{-- Modal: añadir un cliente a la ruta (llamada sobre la marcha) --}}
    <x-modal name="add-stop" max-width="lg">
        <div class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Añadir cliente a la ruta') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Busca el cliente que ha llamado; se añade como una parada más al final.') }}</p>

            <input type="search" wire:model.live.debounce.300ms="clientSearch"
                placeholder="{{ __('Nombre, CIF, teléfono o población…') }}"
                class="mt-4 block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />

            <div class="mt-3 max-h-72 space-y-1.5 overflow-y-auto themed-scrollbar">
                @forelse ($this->clientMatches as $c)
                    <button type="button" wire:click="addClientStop({{ $c->id }})" wire:key="cm-{{ $c->id }}"
                        class="flex w-full flex-col rounded-lg border border-slate-200 p-3 text-left transition-colors hover:border-primary-400 hover:bg-primary-50/60 dark:border-slate-700 dark:hover:border-primary-500 dark:hover:bg-primary-500/10">
                        <span class="font-medium text-slate-800 dark:text-slate-100">
                            {{ $c->name }}
                            @if ($c->service_kind === \App\Enums\ServiceKind::Viaje)
                                <x-ui.badge variant="primary" class="ml-1">{{ __('Viaje') }}</x-ui.badge>
                            @endif
                        </span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">
                            {{ collect([$c->city, $c->tax_id, $c->phone])->filter()->join(' · ') ?: '—' }}
                        </span>
                    </button>
                @empty
                    <p class="py-8 text-center text-sm text-slate-400">
                        {{ mb_strlen(trim($clientSearch)) < 2 ? __('Escribe al menos 2 caracteres.') : __('Ningún cliente coincide.') }}
                    </p>
                @endforelse
            </div>

            <div class="mt-6 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
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

                        <div>
                            <x-input-label :value="__('Reprogramar para otro día (opcional)')" />
                            <input type="date" wire:model="form.reschedule_on" min="{{ now()->addDay()->toDateString() }}"
                                class="mt-1.5 block w-full rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm" />
                            <x-input-error :messages="$errors->get('form.reschedule_on')" class="mt-1.5" />
                            <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Se crea una parada nueva para ese día: en tu ruta si tienes una, o en "Sin asignar" para que oficina la reparta.') }}
                            </p>
                        </div>
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
