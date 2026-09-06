{{--
    Botón de menú móvil: una gota en reposo que, al pulsarla, se transforma en tres gotas
    más pequeñas (con rebote) mientras se despliega el menú. Fijo abajo-derecha, solo en móvil.
    Uso: <x-ui.drop-menu><x-responsive-nav-link href="...">Rutas</x-responsive-nav-link>...</x-ui.drop-menu>
--}}
<div x-data="{ open: false }" class="md:hidden">
    <div
        x-show="open"
        x-cloak
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="open = false"
        class="fixed inset-0 z-40 bg-slate-950/30 backdrop-blur-[2px]"
    ></div>

    <div class="fixed bottom-5 right-5 z-50 flex flex-col items-end gap-3">
        <div
            x-show="open"
            x-cloak
            @click.outside="open = false"
            x-transition:enter="ease-[cubic-bezier(0.34,1.56,0.64,1)] duration-300"
            x-transition:enter-start="opacity-0 scale-75 translate-y-3"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-90 translate-y-2"
            class="glass w-60 origin-bottom-right rounded-2xl p-2"
        >
            <nav class="flex flex-col gap-0.5" @click="open = false">
                {{ $slot }}
            </nav>
        </div>

        <button
            type="button"
            @click="open = !open"
            :aria-expanded="open.toString()"
            aria-label="{{ __('Abrir menú') }}"
            class="glass relative grid h-14 w-14 shrink-0 place-items-center rounded-full text-primary-600 transition-transform active:scale-95 dark:text-primary-300"
        >
            {{-- gota única (reposo) --}}
            <svg
                :class="open ? 'opacity-0 scale-[0.4] rotate-45' : 'opacity-100 scale-100 rotate-0'"
                class="absolute h-6 w-6 transition-all duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)]"
                viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
            >
                <path d="M12 2.25c-.32.4-.82 1.03-1.44 1.85C8.24 7.06 5 11.86 5 15.1a7 7 0 0 0 14 0c0-3.24-3.24-8.04-5.56-11-.62-.82-1.12-1.45-1.44-1.85Z" />
            </svg>

            {{-- tres gotas (abierto), con rebote escalonado --}}
            <svg
                :class="open ? 'opacity-100 scale-100 translate-y-0' : 'opacity-0 scale-[0.4] translate-y-1'"
                class="absolute h-4 w-4 -translate-x-1/2 top-2 left-1/2 transition-all delay-0 duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)]"
                viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
            >
                <path d="M12 2.25c-.32.4-.82 1.03-1.44 1.85C8.24 7.06 5 11.86 5 15.1a7 7 0 0 0 14 0c0-3.24-3.24-8.04-5.56-11-.62-.82-1.12-1.45-1.44-1.85Z" />
            </svg>
            <svg
                :class="open ? 'opacity-100 scale-100 translate-y-0' : 'opacity-0 scale-[0.4] -translate-y-1'"
                class="absolute h-4 w-4 bottom-2.5 left-3 transition-all delay-75 duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)]"
                viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
            >
                <path d="M12 2.25c-.32.4-.82 1.03-1.44 1.85C8.24 7.06 5 11.86 5 15.1a7 7 0 0 0 14 0c0-3.24-3.24-8.04-5.56-11-.62-.82-1.12-1.45-1.44-1.85Z" />
            </svg>
            <svg
                :class="open ? 'opacity-100 scale-100 translate-y-0' : 'opacity-0 scale-[0.4] -translate-y-1'"
                class="absolute h-4 w-4 bottom-2.5 right-3 transition-all delay-150 duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)]"
                viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
            >
                <path d="M12 2.25c-.32.4-.82 1.03-1.44 1.85C8.24 7.06 5 11.86 5 15.1a7 7 0 0 0 14 0c0-3.24-3.24-8.04-5.56-11-.62-.82-1.12-1.45-1.44-1.85Z" />
            </svg>
        </button>
    </div>
</div>
