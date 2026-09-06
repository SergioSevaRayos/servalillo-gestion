<?php

namespace App\Models;

use App\Enums\DeliveryNoteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class DeliveryNote extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = [
        'route_stop_id', 'number', 'issued_at', 'customer_snapshot',
        'delivered_quantity', 'odometer_reading', 'signature_path', 'signer_name',
        'delivery_channel', 'recipient_email', 'pdf_path', 'status', 'failure_reason',
        'sent_at', 'delivered_at', 'delivered_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'customer_snapshot' => 'array',
            'delivered_quantity' => 'decimal:2',
            'odometer_reading' => 'integer',
            'status' => DeliveryNoteStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class);
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
