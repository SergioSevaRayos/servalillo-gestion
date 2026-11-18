<?php

use App\Enums\InvoiceStatus;
use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Invoices\Index;
use App\Models\Client;
use App\Models\Invoice;
use Livewire\Livewire;

function invoice(array $overrides = []): Invoice
{
    return Invoice::create([
        'status' => InvoiceStatus::Pending,
        'customer_snapshot' => ['name' => 'Autocares Alonso', 'tax_id' => 'B04198628', 'address' => 'Av. Mare Nostrum'],
        'delivered_quantity' => 500,
        'service_date' => today(),
        ...$overrides,
    ]);
}

it('administrador y mantenimiento ven /facturas; el chofer no', function () {
    $this->actingAs(makeUser('administrador'))->get('/facturas')->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get('/facturas')->assertOk();
    $this->actingAs(makeUser('chofer'))->get('/facturas')->assertForbidden();
});

it('por defecto lista solo las pendientes y muestra los datos fiscales', function () {
    invoice(['customer_snapshot' => ['name' => 'Pendiente SL', 'tax_id' => 'B11111111']]);
    invoice(['status' => InvoiceStatus::Sent, 'invoice_number' => 'F-1', 'customer_snapshot' => ['name' => 'Enviada SL']]);

    $test = Livewire::actingAs(makeUser('administrador'))->test(Index::class);

    $test->assertSee('Pendiente SL')->assertSee('B11111111')->assertDontSee('Enviada SL');
    $test->set('status', 'all')->assertSee('Enviada SL');
});

it('marcar emitida exige el número de factura y estampa la fecha', function () {
    $invoice = invoice();
    $admin = makeUser('administrador');

    $test = Livewire::actingAs($admin)->test(Index::class)
        ->call('edit', $invoice->id, 'issued')
        ->assertSet('form.status', 'issued')
        ->call('save')
        ->assertHasErrors('form.invoice_number');

    expect($test->html())->toContain('Anota el número de la factura');

    $test->set('form.invoice_number', 'F-2026-001')->call('save')->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->invoice_number)->toBe('F-2026-001')
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->sent_at)->toBeNull()
        ->and($invoice->handled_by)->toBe($admin->id);
});

it('marcar enviada estampa el envío y volver a pendiente limpia las fechas', function () {
    $invoice = invoice(['status' => InvoiceStatus::Issued, 'invoice_number' => 'F-1', 'issued_at' => now()]);

    Livewire::actingAs(makeUser('administrador'))->test(Index::class)
        ->call('edit', $invoice->id, 'sent')
        ->call('save')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->fresh()->sent_at)->not->toBeNull();

    Livewire::actingAs(makeUser('administrador'))->test(Index::class)
        ->call('edit', $invoice->id)
        ->set('form.status', 'pending')
        ->call('save')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->fresh()->issued_at)->toBeNull()
        ->and($invoice->fresh()->sent_at)->toBeNull();
});

it('un chofer no puede editar facturas', function () {
    $invoice = invoice();

    Livewire::actingAs(makeUser('chofer'))->test(Index::class)->assertForbidden();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

it('la ficha de cliente guarda "Siempre factura"', function () {
    $admin = makeUser('administrador');

    Livewire::actingAs($admin)->test(ClientsIndex::class)
        ->call('create')
        ->set('form.name', 'Cliente Factura SL')
        ->set('form.always_invoice', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Client::where('name', 'Cliente Factura SL')->first()->always_invoice)->toBeTrue();
});
