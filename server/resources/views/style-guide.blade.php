<x-app-layout>
    <x-slot name="header">{{ __('Guía de estilo') }}</x-slot>

    <div class="space-y-10">
        {{-- KPIs / glass --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Tarjetas KPI (cristal)') }}
            </h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.stat-card label="Entregas hoy" value="128" trend="+12%" trend-direction="up" />
                <x-ui.stat-card label="Rutas activas" value="6" />
                <x-ui.stat-card label="Incidencias" value="2" trend="-1" trend-direction="down" />
                <x-ui.stat-card label="Tiempo medio" value="34 min" />
            </div>
        </section>

        {{-- Botones --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Botones') }}
            </h2>
            <x-ui.card class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary">Primario</x-ui.button>
                <x-ui.button variant="secondary">Secundario</x-ui.button>
                <x-ui.button variant="ghost">Ghost</x-ui.button>
                <x-ui.button variant="danger">Peligro</x-ui.button>
                <x-ui.button variant="primary" size="sm">Pequeño</x-ui.button>
                <x-ui.button variant="primary" size="lg">Grande</x-ui.button>
                <x-ui.button variant="primary" disabled>Deshabilitado</x-ui.button>
            </x-ui.card>
        </section>

        {{-- Badges --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Estados') }}
            </h2>
            <x-ui.card class="flex flex-wrap items-center gap-2">
                @foreach (\App\Enums\RouteStatus::cases() as $status)
                    <x-ui.badge :variant="$status->badgeVariant()">{{ $status->label() }}</x-ui.badge>
                @endforeach
            </x-ui.card>
        </section>

        {{-- Formulario --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Formulario') }}
            </h2>
            <x-ui.card class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="demo_name" label="Nombre" placeholder="Pedro Ramírez" />
                <x-ui.input name="demo_email" label="Email" type="email" error="Este campo es obligatorio." />
                <x-ui.select name="demo_type" label="Tipo de reparto" placeholder="Selecciona uno">
                    <option>Gasóleo</option>
                    <option>Agua</option>
                </x-ui.select>
                <x-ui.input name="demo_qty" label="Litros" type="number" help="Cantidad estimada en litros." />
                <div class="sm:col-span-2">
                    <x-ui.textarea name="demo_notes" label="Notas" placeholder="Observaciones de la entrega…" />
                </div>
                <div class="sm:col-span-2">
                    <x-ui.checkbox name="demo_check" label="Requiere bomba propia" />
                </div>
            </x-ui.card>
        </section>

        {{-- Tabla responsive --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Tabla → tarjetas en móvil') }}
            </h2>
            <x-ui.table>
                <thead>
                    <tr>
                        <th>{{ __('Camión') }}</th>
                        <th>{{ __('Chofer') }}</th>
                        <th>{{ __('Ruta') }}</th>
                        <th>{{ __('Estado') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (\App\Models\RouteDay::with(['truck', 'driver.user'])->latest('route_date')->take(5)->get() as $route)
                        <tr>
                            <td data-label="{{ __('Camión') }}">{{ $route->truck->code }}</td>
                            <td data-label="{{ __('Chofer') }}">{{ $route->driver->user->name }}</td>
                            <td data-label="{{ __('Ruta') }}">{{ $route->code }}</td>
                            <td data-label="{{ __('Estado') }}">
                                <x-ui.badge :variant="$route->status->badgeVariant()">{{ $route->status->label() }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <p class="text-xs text-slate-400 dark:text-slate-500">
                {{ __('Reduce el ancho de la ventana para ver las filas convertirse en tarjetas.') }}
            </p>
        </section>

        {{-- Modal --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Modal') }}
            </h2>
            <x-ui.card>
                <x-ui.button x-data @click="$dispatch('open-modal', 'demo-modal')">
                    {{ __('Abrir modal de ejemplo') }}
                </x-ui.button>
            </x-ui.card>
            <x-modal name="demo-modal" :show="false" max-width="md">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Modal de ejemplo') }}</h3>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Fondo de cristal, animación con rebote sutil al abrir.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-ui.button variant="secondary" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
                    </div>
                </div>
            </x-modal>
        </section>

        {{-- Toast --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Notificaciones') }}
            </h2>
            <x-ui.card class="flex flex-wrap gap-3">
                <x-ui.button
                    variant="secondary"
                    x-data
                    @click="window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Ruta guardada correctamente.', variant: 'success' } }))"
                >{{ __('Lanzar toast de éxito') }}</x-ui.button>
                <x-ui.button
                    variant="secondary"
                    x-data
                    @click="window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'No se pudo generar el albarán.', variant: 'danger' } }))"
                >{{ __('Lanzar toast de error') }}</x-ui.button>
            </x-ui.card>
        </section>

        {{-- Menú móvil --}}
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ __('Menú móvil (gota animada)') }}
            </h2>
            <x-ui.card>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Reduce la ventana por debajo de 768px: aparece el botón de gota fijo abajo a la derecha. Al pulsarlo se transforma en 3 gotas con rebote y despliega el menú.') }}
                </p>
            </x-ui.card>
        </section>
    </div>
</x-app-layout>
