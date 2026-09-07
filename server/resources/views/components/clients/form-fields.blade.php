@props(['deliveryTypes', 'types', 'editing' => false, 'status' => 'customer'])

@php $prospect = $status === 'prospect'; @endphp

<div class="space-y-4">
    @unless ($editing)
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Tipo de alta') }}</p>
            <div class="mt-1.5 max-w-xs">
                <x-ui.select name="status" wire:model.live="form.status"
                    :help="$prospect ? __('Solo se piden los datos de la llamada. Se valora antes de convertirlo en cliente.') : null">
                    <option value="customer">{{ __('Cliente') }}</option>
                    <option value="prospect">{{ __('Pendiente valoración') }}</option>
                </x-ui.select>
            </div>
        </div>
    @endunless

    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $prospect ? __('Datos de la llamada') : __('Identificación') }}</p>
        <div class="mt-1.5 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
            <x-ui.input name="name" label="{{ __('Nombre / razón social') }}" wire:model="form.name" />
            @if ($prospect)
                <x-ui.input name="phone" label="{{ __('Teléfono') }}" wire:model="form.phone" />
                <x-ui.select name="service_kind" label="{{ __('Tipo de servicio') }}" wire:model="form.service_kind">
                    @foreach (\App\Enums\ServiceKind::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            @else
                <x-ui.input name="tax_id" label="{{ __('CIF / NIF') }}" wire:model="form.tax_id" />
                <x-ui.input name="external_ref" label="{{ __('Código (Access)') }}" wire:model="form.external_ref" />
                <x-ui.select name="service_kind" label="{{ __('Tipo de servicio') }}" wire:model="form.service_kind">
                    @foreach (\App\Enums\ServiceKind::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="client_type" label="{{ __('Tipo de cliente') }}" wire:model="form.client_type" placeholder="{{ __('Sin especificar') }}">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            @endif
        </div>
    </div>

    @unless ($prospect)
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Contacto') }}</p>
            <div class="mt-1.5 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.input name="contact_name" label="{{ __('Persona de contacto') }}" wire:model="form.contact_name" />
                <x-ui.input name="email" type="email" label="{{ __('Email') }}" wire:model="form.email" />
                <x-ui.input name="phone" label="{{ __('Teléfono') }}" wire:model="form.phone" />
                <x-ui.input name="secondary_phone" label="{{ __('Teléfono secundario') }}" wire:model="form.secondary_phone" />
            </div>
        </div>
    @endunless

    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Ubicación') }}</p>
        <div class="mt-1.5 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="sm:col-span-2 lg:col-span-3"><x-ui.input name="address" label="{{ __('Dirección') }}" wire:model="form.address" /></div>
            @unless ($prospect)
                <x-ui.input name="postal_code" label="{{ __('Código postal') }}" wire:model="form.postal_code" />
                <x-ui.input name="city" label="{{ __('Población') }}" wire:model="form.city" />
                <x-ui.input name="province" label="{{ __('Provincia') }}" wire:model="form.province" />
            @endunless
            <div class="grid grid-cols-2 gap-3">
                <x-ui.input name="latitude" label="{{ __('Latitud') }}" wire:model="form.latitude" inputmode="decimal" />
                <x-ui.input name="longitude" label="{{ __('Longitud') }}" wire:model="form.longitude" inputmode="decimal" />
            </div>
        </div>
    </div>

    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Datos del suministro') }}</p>
        <div class="mt-1.5 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
            <x-ui.select name="water_type" label="{{ __('Tipo de agua') }}" wire:model="form.water_type" placeholder="{{ __('Sin especificar') }}">
                @foreach (\App\Enums\WaterType::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input name="quantity_input" type="number" step="any" label="{{ __('Cantidad habitual') }}" wire:model="form.quantity_input"
                :help="__('Se guarda siempre en litros; si eliges m³ se multiplica ×1000.')" />
            <x-ui.select name="quantity_unit" label="{{ __('Unidad') }}" wire:model="form.quantity_unit">
                <option value="L">{{ __('Litros') }}</option>
                <option value="m3">{{ __('Metros cúbicos') }}</option>
            </x-ui.select>
            <x-ui.input name="tank_distance_m" type="number" label="{{ __('Distancia depósito–camión (m)') }}" wire:model="form.tank_distance_m" />
        </div>
    </div>

    @unless ($prospect)
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Reparto habitual') }}</p>
            <div class="mt-1.5 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.select name="default_delivery_type_id" label="{{ __('Producto habitual') }}" wire:model="form.default_delivery_type_id" placeholder="{{ __('Sin definir') }}">
                    @foreach ($deliveryTypes as $dt)
                        <option value="{{ $dt->id }}">{{ $dt->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="frequency_days" type="number" label="{{ __('Periodicidad (días)') }}" wire:model="form.frequency_days" :help="__('En blanco = bajo demanda.')" />
                <x-ui.input name="tank_capacity_liters" type="number" label="{{ __('Capacidad del depósito (L)') }}" wire:model="form.tank_capacity_liters" />
                <x-ui.select name="preferred_channel" label="{{ __('Canal de albarán') }}" wire:model="form.preferred_channel" placeholder="{{ __('Sin preferencia') }}">
                    <option value="email">{{ __('Email') }}</option>
                    <option value="physical">{{ __('Entrega en mano') }}</option>
                </x-ui.select>
                <x-ui.input name="price" type="number" step="any" label="{{ __('Precio (€)') }}" wire:model="form.price" />
                <x-ui.select name="price_type" label="{{ __('Tipo de precio') }}" wire:model="form.price_type">
                    @foreach (\App\Enums\PriceType::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="payment_terms" label="{{ __('Forma de pago') }}" wire:model="form.payment_terms" />
                <x-ui.input name="last_served_on" type="date" label="{{ __('Último reparto') }}" wire:model="form.last_served_on" />
                <div class="flex items-end pb-2">
                    <x-ui.checkbox name="requires_own_pump" label="{{ __('Requiere bomba propia') }}" wire:model="form.requires_own_pump" />
                </div>
            </div>
        </div>
    @endunless

    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Observaciones') }}</p>
        <div class="mt-1.5 space-y-3">
            @unless ($prospect)
                <x-ui.textarea name="access_notes" label="{{ __('Acceso / instrucciones para el chofer') }}" wire:model="form.access_notes" rows="2"
                    placeholder="{{ __('Ej: portón azul al fondo, llamar antes de llegar.') }}" />
            @endunless
            <x-ui.textarea name="notes" label="{{ $prospect ? __('Observaciones de la llamada') : __('Notas generales') }}" wire:model="form.notes" rows="2" />
        </div>
    </div>

    @unless ($prospect)
        <x-ui.checkbox name="is_active" label="{{ __('Cliente activo') }}" wire:model="form.is_active" />
    @endunless
</div>
