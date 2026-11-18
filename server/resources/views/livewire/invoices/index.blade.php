<div>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Facturas') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Clientes que han pedido factura al recibir una entrega. La factura se hace fuera de la app: aquí se anota el número y se controla que se emita y se envíe al cliente.') }}
        </p>
        @if ($pendingCount > 0)
            <p class="mt-2 text-sm font-medium text-amber-700 dark:text-amber-300">
                {{ trans_choice(':count factura pendiente de hacer|:count facturas pendientes de hacer', $pendingCount) }}
            </p>
        @endif
    </div>

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar por cliente, CIF o nº de factura…') }}"
            class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:max-w-xs lg:text-sm"
        />
        <select wire:model.live="status" class="rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm">
            <option value="all">{{ __('Todos los estados') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </select>
        <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
        <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
        <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Entrega') }}</th>
                <th>{{ __('Cliente') }}</th>
                <th>{{ __('CIF / NIF') }}</th>
                <th>{{ __('Litros') }}</th>
                <th>{{ __('Estado') }}</th>
                <th>{{ __('Nº factura') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                <tr wire:key="inv-{{ $invoice->id }}">
                    <td data-label="{{ __('Entrega') }}" class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $invoice->service_date?->format('d/m/Y') ?? '—' }}</td>
                    <td data-label="{{ __('Cliente') }}" class="font-medium text-slate-800 dark:text-slate-100">
                        {{ data_get($invoice->customer_snapshot, 'name', '—') }}
                        @if ($address = data_get($invoice->customer_snapshot, 'address'))
                            <span class="block text-xs font-normal text-slate-400">{{ $address }}</span>
                        @endif
                        @if ($email = data_get($invoice->customer_snapshot, 'email'))
                            <span class="block text-xs font-normal text-slate-400">{{ $email }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('CIF / NIF') }}" class="whitespace-nowrap">{{ data_get($invoice->customer_snapshot, 'tax_id', '—') }}</td>
                    <td data-label="{{ __('Litros') }}">{{ $invoice->delivered_quantity !== null ? number_format($invoice->delivered_quantity, 0, ',', '.').' L' : '—' }}</td>
                    <td data-label="{{ __('Estado') }}">
                        <x-ui.badge :variant="$invoice->status->badgeVariant()">{{ $invoice->status->label() }}</x-ui.badge>
                    </td>
                    <td data-label="{{ __('Nº factura') }}" class="whitespace-nowrap">
                        {{ $invoice->invoice_number ?? '—' }}
                        @if ($invoice->notes)
                            <span class="block text-xs text-slate-400" title="{{ $invoice->notes }}">{{ \Illuminate\Support\Str::limit($invoice->notes, 40) }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            @if ($invoice->deliveryNote)
                                <x-ui.button href="{{ route('delivery-notes.pdf', $invoice->deliveryNote) }}?view=1" target="_blank" variant="ghost" size="sm">{{ __('Albarán') }}</x-ui.button>
                            @endif
                            @if ($invoice->status === \App\Enums\InvoiceStatus::Pending)
                                <x-ui.button size="sm" wire:click="edit({{ $invoice->id }}, 'issued')">{{ __('Marcar emitida') }}</x-ui.button>
                            @elseif ($invoice->status === \App\Enums\InvoiceStatus::Issued)
                                <x-ui.button size="sm" wire:click="edit({{ $invoice->id }}, 'sent')">{{ __('Marcar enviada') }}</x-ui.button>
                            @endif
                            <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $invoice->id }})">{{ __('Editar') }}</x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7"><x-ui.empty-state title="{{ __('Sin facturas') }}" description="{{ __('Aparecerán aquí cuando un chofer marque que un cliente quiere factura.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $invoices->links() }}</div>

    <x-modal name="invoice-edit" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('Factura de :cliente', ['cliente' => data_get($form->invoice?->customer_snapshot, 'name', '')]) }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Anota aquí el número cuando la hayas hecho y márcala como enviada cuando se la mandes al cliente.') }}</p>

            <div class="mt-5 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                <x-ui.select name="status" label="{{ __('Estado') }}" wire:model.live="form.status">
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="invoice_number" label="{{ __('Nº de factura') }}" wire:model="form.invoice_number" />
            </div>
            <div class="mt-3">
                <x-ui.textarea name="notes" label="{{ __('Notas') }}" wire:model="form.notes" :rows="2" />
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'invoice-edit')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>
</div>
