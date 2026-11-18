<div wire:poll.30s>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Avisos') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Configura cuándo quieres que la app te avise. Los avisos llegan a la campana, una vez al producirse y otra al resolverse.') }}
            </p>
        </div>
        <x-ui.button variant="secondary" size="sm" wire:click="evaluateNow">{{ __('Evaluar ahora') }}</x-ui.button>
    </div>

    {{-- Avisos activos --}}
    <section class="mb-8">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Activos ahora') }}</h2>
        @forelse ($active as $event)
            <a href="{{ $event->url }}" wire:navigate wire:key="act-{{ $event->id }}"
                class="surface mb-2 flex items-start justify-between gap-3 rounded-xl border-l-4 border-amber-500 p-3 hover:bg-slate-50 dark:hover:bg-slate-800">
                <div>
                    <p class="font-medium text-slate-900 dark:text-white">{{ $event->rule_type->label() }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ $event->message }}</p>
                </div>
                <span class="whitespace-nowrap text-xs text-slate-400">{{ $event->opened_at->diffForHumans() }}</span>
            </a>
        @empty
            <p class="surface rounded-xl p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ __('Todo en orden: no hay ningún aviso activo.') }}</p>
        @endforelse
    </section>

    {{-- Configuración --}}
    <form wire:submit="save">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Configuración') }}</h2>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @foreach ($types as $type)
                <div class="surface rounded-xl p-4" wire:key="rule-{{ $type->value }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-slate-900 dark:text-white">{{ __($type->label()) }}</h3>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __($type->description()) }}</p>
                        </div>
                        <x-ui.checkbox name="enabled_{{ $type->value }}" label="{{ __('Activo') }}" wire:model="form.items.{{ $type->value }}.enabled" />
                    </div>

                    <div class="mt-3 max-w-[12rem]">
                        @if ($type->usesTime())
                            <x-ui.input name="time_{{ $type->value }}" type="time" label="{{ __('A partir de las') }}" wire:model="form.items.{{ $type->value }}.time" />
                        @else
                            <x-ui.input name="threshold_{{ $type->value }}" type="number" inputmode="numeric"
                                label="{{ __('Umbral') }} ({{ $type->unit() }})" wire:model="form.items.{{ $type->value }}.threshold" />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex justify-end">
            <x-ui.button type="submit">{{ __('Guardar avisos') }}</x-ui.button>
        </div>
    </form>

    {{-- Resueltos recientemente --}}
    @if ($recent->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Resueltos recientemente') }}</h2>
            <div class="surface divide-y divide-slate-100 rounded-xl dark:divide-slate-800">
                @foreach ($recent as $event)
                    <div class="flex items-start justify-between gap-3 p-3" wire:key="rec-{{ $event->id }}">
                        <div>
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $event->rule_type->label() }} · {{ $event->subject_label }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Desde :a hasta :b', ['a' => $event->opened_at->format('d/m H:i'), 'b' => $event->resolved_at->format('H:i')]) }}
                            </p>
                        </div>
                        <x-ui.badge variant="success">{{ __('Resuelto') }}</x-ui.badge>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
