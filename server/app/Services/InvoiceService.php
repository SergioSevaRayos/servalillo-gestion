<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\RouteStop;

/**
 * Alta del control de factura cuando un cliente la pide al recibir una entrega. Idempotente:
 * una parada tiene como mucho una factura (índice único route_stop_id), así que reabrir y
 * volver a cerrar la entrega no duplica nada ni pisa lo que administración ya haya anotado.
 */
class InvoiceService
{
    /** Devuelve la factura y si se ha creado ahora (para avisar a oficina solo la primera vez). */
    public function requestForStop(RouteStop $stop, ?DeliveryNote $note = null): Invoice
    {
        return Invoice::query()->firstOrCreate(
            ['route_stop_id' => $stop->id],
            [
                'delivery_note_id' => $note?->id,
                'status' => InvoiceStatus::Pending,
                // Datos fiscales congelados: si el cliente se edita después, la factura pedida no cambia.
                'customer_snapshot' => [
                    'name' => $stop->customer_name,
                    'tax_id' => $stop->customer_tax_id,
                    'address' => $stop->address,
                    'email' => $note?->recipient_email,
                ],
                'delivered_quantity' => $stop->delivered_quantity,
                'service_date' => $stop->route?->route_date ?? today(),
                'requested_by' => auth()->id(),
            ],
        );
    }
}
