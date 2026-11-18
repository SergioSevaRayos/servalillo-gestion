<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Livewire\Forms\InvoiceForm;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Facturas que los clientes han pedido al recibir una entrega: trabajo de administración.
 * La factura se hace fuera de la app; aquí se controla qué falta (pendiente → emitida → enviada).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public InvoiceForm $form;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /** Por defecto lo que falta por hacer: es lo que administración necesita ver al entrar. */
    #[Url(history: true)]
    public string $status = 'pending';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'from', 'to');
        $this->status = 'pending';
        $this->resetPage();
    }

    public function edit(Invoice $invoice, ?string $status = null): void
    {
        $this->authorize('update', $invoice);

        $this->form->setInvoice($invoice);

        // Atajos "Marcar emitida/enviada": abren el mismo modal con el estado ya elegido.
        if ($status && InvoiceStatus::tryFrom($status)) {
            $this->form->status = $status;
        }

        $this->dispatch('open-modal', 'invoice-edit');
    }

    public function save(): void
    {
        $this->authorize('update', $this->form->invoice);

        $invoice = $this->form->save();

        $this->dispatch('close-modal', 'invoice-edit');
        $this->dispatch('toast', message: 'Factura de '.data_get($invoice->customer_snapshot, 'name', 'cliente').' actualizada.', variant: 'success');
    }

    public function render()
    {
        $invoices = Invoice::query()
            ->with('deliveryNote')
            ->when($this->search, function ($q) {
                $term = "%{$this->search}%";
                $q->where(fn ($q) => $q
                    ->where('invoice_number', 'ilike', $term)
                    ->orWhere('customer_snapshot->name', 'ilike', $term)
                    ->orWhere('customer_snapshot->tax_id', 'ilike', $term));
            })
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->from, fn ($q) => $q->where('service_date', '>=', Carbon::parse($this->from)->toDateString()))
            ->when($this->to, fn ($q) => $q->where('service_date', '<=', Carbon::parse($this->to)->toDateString()))
            ->latest('service_date')
            ->latest('id')
            ->paginate(20);

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'statuses' => InvoiceStatus::cases(),
            'pendingCount' => Invoice::query()->pending()->count(),
        ]);
    }
}
