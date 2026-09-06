{{--
    Notificaciones globales. Desde cualquier Livewire component: $this->dispatch('toast', message: '...', variant: 'success');
    Desde JS: window.dispatchEvent(new CustomEvent('toast', { detail: { message: '...', variant: 'danger' } }))
--}}
<div
    x-data="{ toasts: [] }"
    x-on:toast.window="toasts.push({ id: Date.now() + Math.random(), ...$event.detail, }); setTimeout(() => toasts.shift(), 4000)"
    class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:right-4 sm:left-auto"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="ease-[cubic-bezier(0.34,1.56,0.64,1)] duration-300"
            x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="glass pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl p-4 sm:w-96"
        >
            <span
                class="mt-0.5 h-2 w-2 shrink-0 rounded-full"
                :class="{
                    'bg-emerald-500': (toast.variant ?? 'success') === 'success',
                    'bg-amber-500': toast.variant === 'warning',
                    'bg-rose-500': toast.variant === 'danger',
                    'bg-primary-500': toast.variant === 'info',
                }"
            ></span>
            <p class="text-sm text-slate-700 dark:text-slate-200" x-text="toast.message"></p>
        </div>
    </template>
</div>
