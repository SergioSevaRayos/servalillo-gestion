@props(['form', 'routes'])

{{--
    "Planificar reparto" ampliado (2026-09-13): lote de paradas para un rango de fechas + días
    de la semana, en la ruta elegida (o "Sin asignar"). Compartido entre Clients\Index (botón
    por fila) y Clients\Show (ficha) — ambos exponen $planForm/$this->planRoutes con los mismos
    nombres, así que este componente sirve para los dos sin cambios. Ver
    App\Services\ClientDeliveryPlanner para el diseño completo.
--}}
<x-modal name="client-plan" max-width="lg">
    <form wire:submit="savePlan" class="p-6">
        <h3 class="text-lg font-medium text-slate-900 dark:text-white">
            {{ __('Planificar reparto') }}
            @if ($form->client)
                <span class="font-normal text-slate-500 dark:text-slate-400">— {{ $form->client->name }}</span>
            @endif
        </h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Genera de una vez las paradas del rango elegido. No es un calendario permanente: para seguir más adelante, vuelve a planificar.') }}
        </p>

        <div class="mt-5 space-y-4">
            {{-- Sin `placeholder` a propósito: es un `disabled selected` que el navegador no
                 honra visualmente si hay opciones reales sin `disabled` (ver el gotcha de
                 x-ui.select en CLAUDE.md) — con el valor por defecto siendo "Sin asignar", que
                 el select mostrara la primera ruta real sin estarlo de verdad confundiría a
                 oficina. Se deja como opción normal, seleccionable de vuelta en cualquier momento. --}}
            <x-ui.select name="plan_route_id" label="{{ __('Ruta') }}" wire:model="planForm.route_id">
                <option value="">{{ __('Sin asignar (a la columna del tablero)') }}</option>
                @foreach ($routes as $route)
                    <option value="{{ $route->id }}">{{ $route->truck->code }} · {{ $route->driver->user->name }}</option>
                @endforeach
            </x-ui.select>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.date-input name="plan_starts_on" label="{{ __('Desde') }}" wire:model="planForm.starts_on" />
                <x-ui.date-input name="plan_ends_on" label="{{ __('Hasta') }}" wire:model="planForm.ends_on" />
            </div>

            <div>
                <x-input-label :value="__('Días de la semana')" />
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach (\App\Models\Client::WEEKDAY_LABELS as $num => $letter)
                        <button type="button" wire:key="plan-weekday-{{ $num }}" wire:click="togglePlanWeekday({{ $num }})" @class([
                            'flex h-9 w-9 items-center justify-center rounded-lg border text-sm font-semibold transition-colors',
                            'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-500 dark:bg-primary-500/15 dark:text-primary-200' => in_array($num, $form->weekdays, true),
                            'border-slate-300 text-slate-500 hover:border-slate-400 dark:border-slate-700 dark:text-slate-400' => ! in_array($num, $form->weekdays, true),
                        ])>{{ $letter }}</button>
                    @endforeach
                </div>
                @error('planForm.weekdays') <p class="mt-1.5 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
            <x-ui.button type="submit">{{ __('Planificar') }}</x-ui.button>
        </div>
    </form>
</x-modal>
