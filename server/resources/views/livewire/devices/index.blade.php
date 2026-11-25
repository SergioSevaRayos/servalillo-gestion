<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Dispositivos') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Móviles con la APK de tracking instalada. Cada uno se enrola solo; aquí le asignas el chofer, revocas su acceso o lo desactivas, y gestionas su protección (capturas de pantalla, desinstalación y clave).') }}
            </p>
        </div>

        {{-- Descarga de la APK tracker para instalarla o actualizarla en un móvil (docs/24). --}}
        <div class="shrink-0 sm:text-right">
            @if ($apk = $this->apk)
                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button wire:click="openProvisioning" class="w-full justify-center sm:w-auto">
                        {{ __('Dar de alta un móvil') }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" href="{{ route('devices.apk') }}" download class="w-full justify-center sm:w-auto">
                        {{ __('Descargar APK del tracker') }}
                    </x-ui.button>
                </div>
                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                    @if ($apk['version']) {{ __('Versión :v', ['v' => $apk['version']]) }} · @endif
                    {{ $apk['built_at']?->format('d/m/Y') }} · {{ number_format($apk['size'] / 1048576, 1, ',', '.') }} MB
                </p>
            @else
                <p class="text-xs text-slate-400">{{ __('Aún no se ha publicado ninguna APK para descargar.') }}</p>
            @endif
        </div>
    </div>

    {{-- Una tarjeta por móvil (son pocos): la tabla de 9 columnas quedaba ancha, con las acciones
         apiladas y scroll horizontal. Las acciones van en el desplegable "Acciones". --}}
    <div class="space-y-3">
        @forelse ($this->devices as $device)
            @php
                $state = $device->protectionState();
                [$protVariant, $protText] = match ($state) {
                    'protected' => ['success', __('Protegido')],
                    'pending' => ['warning', $device->release_requested ? __('Liberando…') : __('Pendiente')],
                    'unmanaged' => ['danger', __('Sin administración')],
                    'released' => ['neutral', __('Liberado')],
                    default => ['neutral', $device->reported_device_owner ? __('Sin restricciones') : __('Sin proteger')],
                };
                $itemClass = 'block w-full px-4 py-2 text-start text-sm text-slate-700 transition hover:bg-slate-900/5 dark:text-slate-200 dark:hover:bg-white/10';
            @endphp

            <x-ui.card :padded="false" wire:key="device-{{ $device->id }}" class="p-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                wire:key="device-driver-{{ $device->id }}-{{ $device->driver_id ?? 'none' }}"
                                wire:change="assign({{ $device->id }}, $event.target.value)"
                                aria-label="{{ __('Chofer') }}"
                                class="w-full rounded-lg border-slate-300 text-sm font-medium shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:w-64"
                            >
                                <option value="">{{ __('— Sin asignar —') }}</option>
                                @foreach ($this->drivers as $driver)
                                    <option value="{{ $driver->id }}" @selected($device->driver_id === $driver->id)>{{ $driver->user?->name }}</option>
                                @endforeach
                            </select>

                            @unless ($device->driver_id)
                                <x-ui.badge variant="warning">{{ __('Sin asignar') }}</x-ui.badge>
                            @endunless
                            <x-ui.badge :variant="$device->is_active ? 'success' : 'neutral'">
                                {{ $device->is_active ? __('Activo') : __('Desactivado') }}
                            </x-ui.badge>
                            <x-ui.badge :variant="$protVariant">{{ $protText }}</x-ui.badge>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                            <div>
                                <dt class="text-slate-400">{{ __('Última señal') }}</dt>
                                <dd class="mt-0.5 text-slate-700 dark:text-slate-200">
                                    @if ($device->last_seen_at)
                                        <span title="{{ $device->last_seen_at->format('d/m/Y H:i') }}">{{ $device->last_seen_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400">{{ __('nunca') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-400">{{ __('App') }}</dt>
                                <dd class="mt-0.5 capitalize text-slate-700 dark:text-slate-200">{{ $device->platform }} · {{ $device->app_version ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-400">{{ __('Posiciones') }}</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-700 dark:text-slate-200">{{ number_format($device->positions_count, 0, ',', '.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-400">{{ __('Protección sincronizada') }}</dt>
                                <dd class="mt-0.5 text-slate-700 dark:text-slate-200">
                                    @if ($device->policies_reported_at)
                                        <span title="{{ $device->policies_reported_at->format('d/m/Y H:i') }}">{{ $device->policies_reported_at->diffForHumans() }}</span>
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-2 truncate font-mono text-[11px] text-slate-400" title="{{ $device->install_identifier }}">{{ $device->install_identifier }}</p>
                    </div>

                    <x-dropdown align="right" width="w-52">
                        <x-slot name="trigger">
                            <x-ui.button variant="secondary" size="sm" class="w-full sm:w-auto">
                                {{ __('Acciones') }}
                                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
                            </x-ui.button>
                        </x-slot>
                        <x-slot name="content">
                            <button type="button" class="{{ $itemClass }}" wire:click="editSecurity({{ $device->id }})">{{ __('Seguridad') }}</button>
                            @if ($device->positions_count > 0)
                                <button type="button" class="{{ $itemClass }}" wire:click="locate({{ $device->id }})">{{ __('Localizar') }}</button>
                            @endif
                            <button type="button" class="{{ $itemClass }}" wire:click="toggleActive({{ $device->id }})">
                                {{ $device->is_active ? __('Desactivar') : __('Reactivar') }}
                            </button>
                            <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
                            <button type="button" class="{{ $itemClass }}"
                                wire:click="revoke({{ $device->id }})"
                                wire:confirm="{{ __('¿Revocar el acceso de este dispositivo? La APK tendrá que volver a enrolarse.') }}"
                            >{{ __('Revocar acceso') }}</button>
                            <button type="button" class="{{ $itemClass }} !text-rose-600 dark:!text-rose-400"
                                wire:click="delete({{ $device->id }})"
                                wire:confirm="{{ __('¿Eliminar el dispositivo y todas sus posiciones GPS? No se puede deshacer.') }}"
                            >{{ __('Eliminar') }}</button>
                        </x-slot>
                    </x-dropdown>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="py-10 text-center text-sm text-slate-400">
                {{ __('Ningún dispositivo enrolado todavía.') }}
            </x-ui.card>
        @endforelse
    </div>

    <x-device-map-modal />

    {{-- Alta de un móvil por QR (docs/24): Android descarga la APK y la deja como administradora. --}}
    <x-modal name="device-provisioning" max-width="3xl">
        <div class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Dar de alta un móvil') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Para móviles nuevos o recién restablecidos de fábrica. El móvil descarga la app, la instala y la deja lista para protegerlo, sin cables ni ordenador.') }}
            </p>

            <div class="mt-5 grid grid-cols-1 gap-6 md:grid-cols-[auto,1fr]">
                <div class="flex flex-col items-center">
                    <div class="rounded-xl bg-white p-3 shadow-soft-sm ring-1 ring-slate-200 dark:ring-slate-700">
                        @if ($qr = $this->provisioningQr)
                            <div class="h-64 w-64 [&>svg]:h-full [&>svg]:w-full">{!! $qr !!}</div>
                        @else
                            <div class="flex h-64 w-64 items-center justify-center text-center text-sm text-slate-400">
                                {{ __('Corrige los datos de la Wi-Fi para ver el código.') }}
                            </div>
                        @endif
                    </div>
                    @if ($provisionExpiresAt)
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Válido hasta el :d', ['d' => \Illuminate\Support\Carbon::parse($provisionExpiresAt)->timezone(config('app.timezone'))->format('d/m/Y H:i')]) }}
                        </p>
                    @endif
                </div>

                <div>
                    <ol class="list-decimal space-y-1.5 pl-5 text-sm text-slate-700 dark:text-slate-300">
                        <li>{{ __('Restablece el móvil de fábrica (o enciéndelo si es nuevo) y espera a la pantalla de bienvenida.') }}</li>
                        <li>{{ __('Toca 6 veces seguidas en un mismo punto vacío de esa pantalla: se abre el lector de códigos QR.') }}</li>
                        <li>{{ __('Si lo pide, conéctalo a una Wi-Fi. Escanea este código.') }}</li>
                        <li>{{ __('Acepta los avisos de "dispositivo gestionado por tu organización". El móvil descarga e instala la app (unos minutos).') }}</li>
                        <li>{{ __('Termina de configurar el móvil, abre "Servalillo Tracker" y concede los permisos que pide.') }}</li>
                        <li>{{ __('El móvil aparecerá en esta lista: asígnale el chofer y, en "Seguridad", pon la clave y las restricciones.') }}</li>
                    </ol>

                    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.input
                            name="wifi_ssid" wire:model.live.debounce.500ms="provisioning.wifi_ssid"
                            label="{{ __('Wi-Fi (opcional)') }}" autocomplete="off"
                            help="{{ __('Si la pones, el móvil se conecta solo al escanear.') }}"
                        />
                        <x-ui.input
                            name="wifi_password" type="password" wire:model.live.debounce.500ms="provisioning.wifi_password"
                            label="{{ __('Contraseña de la Wi-Fi') }}" autocomplete="new-password"
                        />
                    </div>

                    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('El código lleva un enlace temporal para descargar la app: no lo compartas fuera de la empresa. Si el móvil no muestra el lector al tocar 6 veces, ese modelo no admite el alta por QR: avisa al servicio técnico.') }}
                    </p>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
    </x-modal>

    {{-- Protección del terminal (docs/24). La APK tracker, como administradora del dispositivo,
         aplica estas restricciones en todo el móvil y pide la clave para abrirla. --}}
    <x-modal name="device-security" max-width="2xl">
        @php $editing = $this->security->editing; @endphp
        <form wire:submit="saveSecurity" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">
                {{ __('Seguridad del terminal') }}
                @if ($editing)
                    <span class="text-slate-500 dark:text-slate-400">· {{ $editing->driver?->user?->name ?? $editing->label }}</span>
                @endif
            </h3>

            @if ($editing)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    @if ($editing->reported_device_owner === null)
                        {{ __('El terminal aún no ha informado de su estado. Los cambios se aplicarán cuando sincronice (cada pocos minutos).') }}
                    @elseif (! $editing->reported_device_owner)
                        <span class="text-amber-600 dark:text-amber-400">{{ __('La APK no es administradora de este móvil: hasta darlo de alta con el código QR ("Dar de alta un móvil"), no puede aplicar ninguna restricción.') }}</span>
                    @else
                        {{ __('La APK es administradora de este móvil. Última sincronización: :t.', ['t' => $editing->policies_reported_at?->diffForHumans() ?? '—']) }}
                    @endif
                </p>
            @endif

            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ([
                    'block_screen_capture' => [__('Bloquear capturas de pantalla'), __('Ni capturas ni grabaciones de pantalla en todo el móvil.')],
                    'block_uninstall' => [__('Impedir desinstalar la APK'), __('El chofer no puede quitar el tracker.')],
                    'block_factory_reset' => [__('Impedir restablecer de fábrica'), __('Desde los Ajustes del móvil.')],
                    'block_safe_boot' => [__('Impedir el modo seguro'), __('Evita arrancar el móvil sin apps de terceros.')],
                ] as $field => [$label, $help])
                    @php $reported = $editing?->reported_policies[$field] ?? null; @endphp
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <input type="checkbox" wire:model="security.{{ $field }}" class="mt-0.5 rounded border-slate-300 text-primary-600 shadow-soft-sm focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800" />
                        <span class="text-sm">
                            <span class="block font-medium text-slate-800 dark:text-slate-100">{{ $label }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $help }}</span>
                            @if ($editing?->reported_device_owner)
                                <span class="mt-1 block text-xs {{ $reported ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400' }}">
                                    {{ $reported ? __('Aplicado en el móvil') : __('No aplicado en el móvil') }}
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input
                    name="pin" type="password" inputmode="numeric" autocomplete="new-password" maxlength="8"
                    wire:model="security.pin"
                    label="{{ $editing?->hasUnlockPin() ? __('Nueva clave (opcional)') : __('Clave del terminal') }}"
                    help="{{ __('De 4 a 8 números. Se pide para abrir la APK y desbloquear el móvil.') }}"
                />
                <x-ui.input
                    name="pin_confirmation" type="password" inputmode="numeric" autocomplete="new-password" maxlength="8"
                    wire:model="security.pin_confirmation"
                    label="{{ __('Repite la clave') }}"
                />
            </div>
            @if ($editing?->hasUnlockPin())
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Este terminal ya tiene clave. Déjalo en blanco para mantenerla.') }}</p>
            @endif

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap gap-2">
                    @if ($editing)
                        <x-ui.button
                            variant="ghost" size="sm" type="button"
                            wire:click="release({{ $editing->id }})"
                            wire:confirm="{{ __('¿Liberar este terminal? Se quitarán todas las restricciones y la APK dejará de ser administradora del móvil (se podrá desinstalar y restablecer). Hazlo antes de retirar o reutilizar el móvil.') }}"
                            class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
                        >{{ __('Liberar terminal') }}</x-ui.button>
                        <x-ui.button
                            variant="ghost" size="sm" type="button"
                            wire:click="applySecurityToAll"
                            wire:confirm="{{ __('¿Aplicar estas restricciones a todos los terminales activos? La clave de cada uno no cambia.') }}"
                        >{{ __('Aplicar a todos') }}</x-ui.button>
                    @endif
                </div>
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
                </div>
            </div>
        </form>
    </x-modal>
</div>
