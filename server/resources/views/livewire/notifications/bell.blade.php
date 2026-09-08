@php
    // Trazos heroicons (outline). La clave `icon` de cada notificación elige uno.
    $iconPaths = [
        'route' => 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
        'wrench' => 'M21.75 6.75a4.5 4.5 0 0 1-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 1 1-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 0 1 6.336-4.486l-3.276 3.276a3.004 3.004 0 0 0 2.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852Z',
        'chat' => 'M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z',
        'flag' => 'M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5',
    ];
@endphp

<div wire:poll.30s>
    <x-dropdown align="right" width="w-80">
        <x-slot name="trigger">
            <button type="button"
                class="relative grid h-9 w-9 place-items-center rounded-full text-slate-600 transition hover:bg-slate-900/5 dark:text-slate-300 dark:hover:bg-white/10"
                aria-label="{{ __('Notificaciones') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                </svg>
                @if ($this->unreadCount)
                    <span class="absolute -right-0.5 -top-0.5 grid min-w-[1.15rem] place-items-center rounded-full bg-rose-500 px-1 text-[0.65rem] font-semibold leading-4 text-white">
                        {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
                    </span>
                @endif
            </button>
        </x-slot>

        <x-slot name="content">
          <div class="overflow-hidden rounded-xl bg-white ring-1 ring-slate-900/5 dark:bg-slate-900 dark:ring-white/10">
            <div class="flex items-center justify-between gap-2 px-3 py-2">
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Notificaciones') }}</span>
                @if ($this->unreadCount)
                    <button type="button" wire:click="markAllRead" x-on:click.stop
                        class="text-xs font-medium text-primary-600 hover:text-primary-800 dark:text-primary-400">
                        {{ __('Marcar todo leído') }}
                    </button>
                @endif
            </div>

            <div class="max-h-96 overflow-y-auto themed-scrollbar border-t border-slate-900/10 dark:border-white/10">
                @forelse ($this->items as $n)
                    <div wire:key="notif-{{ $n->id }}"
                        class="group relative transition hover:bg-slate-900/5 dark:hover:bg-white/5">
                        <button type="button" wire:click="markRead('{{ $n->id }}')"
                            class="flex w-full gap-3 py-2.5 pl-3 pr-9 text-start">
                            <span @class([
                                'mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full',
                                'bg-primary-500/10 text-primary-600 dark:text-primary-300' => is_null($n->read_at),
                                'bg-slate-500/10 text-slate-400' => ! is_null($n->read_at),
                            ])>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="{{ $iconPaths[$n->data['icon'] ?? ''] ?? $iconPaths['flag'] }}" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $n->data['title'] ?? __('Notificación') }}</span>
                                    @if (is_null($n->read_at))
                                        <span class="h-2 w-2 shrink-0 rounded-full bg-primary-500"></span>
                                    @endif
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $n->data['body'] ?? '' }}</span>
                                <span class="mt-0.5 block text-[0.7rem] text-slate-400">{{ $n->created_at->diffForHumans() }}</span>
                            </span>
                        </button>
                        <button type="button" wire:click="deleteOne('{{ $n->id }}')" x-on:click.stop
                            title="{{ __('Eliminar') }}" aria-label="{{ __('Eliminar notificación') }}"
                            class="absolute right-1.5 top-2 grid h-7 w-7 place-items-center rounded-full text-slate-400 opacity-0 transition hover:bg-slate-900/10 hover:text-slate-600 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-white/10 dark:hover:text-slate-200">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                @empty
                    <p class="px-3 py-6 text-center text-sm text-slate-400">{{ __('Sin notificaciones') }}</p>
                @endforelse
            </div>

            @if ($this->items->isNotEmpty())
                <div class="border-t border-slate-900/10 px-3 py-2 dark:border-white/10">
                    <button type="button" wire:click="clearAll" x-on:click.stop
                        wire:confirm="{{ __('¿Eliminar todas las notificaciones?') }}"
                        class="text-xs font-medium text-rose-600 hover:text-rose-700 dark:text-rose-400">
                        {{ __('Eliminar todas') }}
                    </button>
                </div>
            @endif
          </div>
        </x-slot>
    </x-dropdown>
</div>
