{{--
    Al registrar un pre-cliente (pendiente de valoración) se abre este modal con un resumen de
    la llamada listo para copiar y enviar por WhatsApp a los encargados. El componente Livewire
    (Clients\Index::save()) emite `open-prospect-summary` con { text }; el Alpine `prospectSummary`
    (resources/js/app.js) abre el modal y guarda el texto.
--}}
<x-modal name="prospect-summary" max-width="lg">
    <div class="p-6" x-data="prospectSummary()" x-on:open-prospect-summary.window="open($event.detail)">
        <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Pre-cliente registrado') }}</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Cópialo y envíaselo a los encargados por WhatsApp para que lo valoren.') }}
        </p>

        <textarea
            x-ref="text"
            x-text="text"
            readonly
            rows="9"
            class="surface mt-4 block w-full resize-none rounded-lg border-slate-300 font-mono text-sm text-slate-700 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:text-slate-200"
            x-on:click="$refs.text.select()"
        ></textarea>

        <div class="mt-5 flex justify-end gap-3">
            <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            <x-ui.button type="button" x-on:click="copy()">
                <span x-show="! copied">{{ __('Copiar') }}</span>
                <span x-show="copied" x-cloak>{{ __('¡Copiado!') }}</span>
            </x-ui.button>
        </div>
    </div>
</x-modal>
