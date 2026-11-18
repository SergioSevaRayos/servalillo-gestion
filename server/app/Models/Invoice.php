<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Factura que un cliente ha pedido al recibir una entrega. La factura en sí la hace
 * administración fuera de la app: aquí solo se lleva el control (pendiente → emitida →
 * enviada), el nº que le han puesto y las notas.
 */
class Invoice extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'route_stop_id', 'delivery_note_id', 'status', 'invoice_number', 'customer_snapshot',
        'delivered_quantity', 'service_date', 'issued_at', 'sent_at', 'notes',
        'requested_by', 'handled_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'customer_snapshot' => 'array',
            'delivered_quantity' => 'decimal:2',
            'service_date' => 'date',
            'issued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Pending);
    }
}
