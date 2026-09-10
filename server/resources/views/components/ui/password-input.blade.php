@props(['label' => null, 'name', 'error' => null, 'help' => null, 'suggest' => false])

@php
$id = $attributes->get('id', $name);
$errorMsg = $error ?? ($errors->first($name) ?: null);
@endphp

<div
    x-data="{
        show: false,
        generate() {
            const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
            const digits = '23456789';
            const all = letters + digits;
            const pick = (pool) => pool[Math.floor(Math.random() * pool.length)];
            const chars = [pick(letters), pick(letters), pick(digits), pick(digits)];
            for (let i = 0; i < 8; i++) chars.push(pick(all));
            for (let i = chars.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [chars[i], chars[j]] = [chars[j], chars[i]];
            }
            this.show = true;
            $refs.input.value = chars.join('');
            $refs.input.dispatchEvent(new Event('input'));
        },
    }"
>
    @if ($label)
        <x-input-label :for="$id" :value="$label" />
    @endif

    <div class="relative">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            x-ref="input"
            :type="show ? 'text' : 'password'"
            autocomplete="new-password"
            {{ $attributes->merge([
                'class' => 'block w-full rounded-lg shadow-soft-sm sm:text-sm placeholder:text-slate-400 dark:placeholder:text-slate-500 dark:bg-slate-800 dark:text-slate-100 '
                    . ($suggest ? 'pr-16 ' : 'pr-9 ')
                    . ($errorMsg
                        ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500 dark:border-rose-500/50'
                        : 'border-slate-300 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700'),
            ]) }}
        />

        <div class="absolute inset-y-0 right-0 flex items-center gap-0.5 pr-2">
            @if ($suggest)
                <button
                    type="button"
                    x-on:click="generate()"
                    class="rounded p-1 text-slate-400 hover:text-primary-600 dark:text-slate-500 dark:hover:text-primary-400"
                    title="{{ __('Sugerir una contraseña') }}"
                >
                    <span class="sr-only">{{ __('Sugerir una contraseña') }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" />
                    </svg>
                </button>
            @endif

            <button
                type="button"
                x-on:click="show = ! show"
                class="rounded p-1 text-slate-400 hover:text-primary-600 dark:text-slate-500 dark:hover:text-primary-400"
                :title="show ? '{{ __('Ocultar contraseña') }}' : '{{ __('Mostrar contraseña') }}'"
            >
                <span class="sr-only" x-text="show ? '{{ __('Ocultar contraseña') }}' : '{{ __('Mostrar contraseña') }}'"></span>
                <svg x-show="! show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-4 w-4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <svg x-show="show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-4 w-4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                </svg>
            </button>
        </div>
    </div>

    @if ($help && ! $errorMsg)
        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
    @endif

    @if ($errorMsg)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $errorMsg }}</p>
    @endif
</div>
