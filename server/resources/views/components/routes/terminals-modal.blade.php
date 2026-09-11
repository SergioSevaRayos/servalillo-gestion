@props(['route']) {{-- Route|null (`$this->terminalsRoute` del componente que lo incluye) --}}

<x-modal name="route-terminals" max-width="lg">
    @if ($route)
        <div class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Terminales vinculados') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $route->truck->code }} · {{ $route->driver->user->name }} — {{ __('cualquier chofer que inicie sesión desde un terminal vinculado pasa directamente a gestionar esta ruta ese día.') }}
            </p>

            <form wire:submit="createTerminal" class="mt-4 flex items-end gap-2">
                <div class="flex-1">
                    <x-ui.input name="newTerminalLabel" label="{{ __('Etiqueta (opcional)') }}" placeholder="{{ __('Móvil de la cabina') }}" wire:model="newTerminalLabel" />
                </div>
                <x-ui.button type="submit" size="sm">{{ __('Vincular un terminal nuevo') }}</x-ui.button>
            </form>

            <ul class="mt-5 max-h-[45vh] space-y-3 overflow-y-auto themed-scrollbar">
                @forelse ($route->terminals as $terminal)
                    <li class="rounded-xl border border-slate-200 p-3 dark:border-slate-700" wire:key="terminal-{{ $terminal->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">
                                    {{ $terminal->label ?: __('Sin etiqueta') }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    @if ($terminal->isRevoked())
                                        <x-ui.badge variant="neutral">{{ __('Revocado') }}</x-ui.badge>
                                    @elseif ($terminal->paired_at)
                                        {{ __('Vinculado el :date', ['date' => $terminal->paired_at->format('d/m/Y H:i')]) }}
                                        @if ($terminal->last_used_at)
                                            · {{ __('último uso :date', ['date' => $terminal->last_used_at->diffForHumans()]) }}
                                        @endif
                                    @else
                                        {{ __('Aún sin vincular — abre el enlace desde el teléfono del camión.') }}
                                    @endif
                                </p>
                            </div>
                            @unless ($terminal->isRevoked())
                                <x-ui.button
                                    variant="ghost" size="sm"
                                    wire:click="revokeTerminal({{ $terminal->id }})"
                                    wire:confirm="{{ __('¿Revocar este terminal? El navegador que lo tenga vinculado dejará de pasar a esta ruta.') }}"
                                    class="shrink-0 !text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                                >{{ __('Revocar') }}</x-ui.button>
                            @endunless
                        </div>

                        @unless ($terminal->isRevoked())
                            <div x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText($refs.link.value); } catch { $refs.link.select(); } this.copied = true; setTimeout(() => this.copied = false, 2000); } }"
                                class="mt-2 flex items-center gap-2">
                                <input x-ref="link" type="text" readonly
                                    value="{{ route('terminal.pair', $terminal->token) }}"
                                    class="min-w-0 flex-1 rounded-lg border-slate-300 bg-slate-50 text-xs text-slate-500 shadow-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400"
                                    x-on:click="$el.select()" />
                                <x-ui.button type="button" variant="secondary" size="sm" x-on:click="copy()" class="shrink-0">
                                    <span x-show="! copied">{{ __('Copiar') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('¡Copiado!') }}</span>
                                </x-ui.button>
                            </div>
                        @endunless
                    </li>
                @empty
                    <li class="py-4 text-center text-sm text-slate-400">{{ __('Sin terminales vinculados todavía.') }}</li>
                @endforelse
            </ul>

            <div class="mt-5 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
    @endif
</x-modal>
