<div>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Albaranes') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Un albarán por cada entrega completada. El PDF y el envío se procesan en segundo plano.') }}</p>
    </div>

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar por nº, cliente o email…') }}"
            class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:max-w-xs lg:text-sm"
        />
        <select wire:model.live="status" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Todos los estados') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="channel" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Todos los canales') }}</option>
            @foreach ($channelOptions as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
        <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
        <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Nº') }}</th>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Cliente') }}</th>
                <th>{{ __('Litros') }}</th>
                <th>{{ __('Canal') }}</th>
                <th>{{ __('Estado') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($notes as $note)
                <tr wire:key="dn-{{ $note->id }}">
                    <td data-label="{{ __('Nº') }}" class="whitespace-nowrap font-medium text-slate-800 dark:text-slate-100">{{ $note->number }}</td>
                    <td data-label="{{ __('Fecha') }}" class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $note->issued_at->format('d/m/Y H:i') }}</td>
                    <td data-label="{{ __('Cliente') }}">
                        {{ data_get($note->customer_snapshot, 'name', '—') }}
                        @if ($note->recipient_email)<span class="block text-xs text-slate-400">{{ $note->recipient_email }}</span>@endif
                    </td>
                    <td data-label="{{ __('Litros') }}">{{ $note->delivered_quantity !== null ? number_format($note->delivered_quantity, 0, ',', '.').' L' : '—' }}</td>
                    <td data-label="{{ __('Canal') }}">{{ $channelOptions[$note->delivery_channel] ?? $note->delivery_channel }}</td>
                    <td data-label="{{ __('Estado') }}">
                        <x-ui.badge :variant="$note->status->badgeVariant()">{{ $note->status->label() }}</x-ui.badge>
                        @if ($note->status->value === 'failed' && $note->failure_reason)
                            <span class="block text-xs text-rose-500">{{ \Illuminate\Support\Str::limit($note->failure_reason, 50) }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button href="{{ route('delivery-notes.pdf', $note) }}" variant="ghost" size="sm">{{ __('PDF') }}</x-ui.button>
                            @can('regenerate', $note)
                                <x-ui.button variant="ghost" size="sm" wire:click="reprocess({{ $note->id }})"
                                    wire:confirm="{{ __('¿Volver a generar y enviar este albarán?') }}">{{ __('Reprocesar') }}</x-ui.button>
                            @endcan
                            @if ($note->delivery_channel === 'physical' && ! $note->status->isDelivered())
                                @can('markDelivered', $note)
                                    <x-ui.button variant="ghost" size="sm" wire:click="markDelivered({{ $note->id }})">{{ __('Marcar entregado') }}</x-ui.button>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7"><x-ui.empty-state title="{{ __('Sin albaranes') }}" description="{{ __('Aparecerán aquí a medida que los chóferes completen entregas.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $notes->links() }}</div>
</div>
