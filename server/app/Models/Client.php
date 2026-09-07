<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Enums\ServiceKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Client extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'external_ref', 'name', 'tax_id', 'client_type', 'service_kind',
        'contact_name', 'phone', 'secondary_phone', 'email',
        'address', 'postal_code', 'city', 'province', 'latitude', 'longitude',
        'default_delivery_type_id', 'typical_quantity', 'frequency_days', 'tank_capacity_liters',
        'requires_own_pump', 'preferred_channel', 'price_per_liter', 'payment_terms', 'last_served_on',
        'access_notes', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'client_type' => ClientType::class,
            'service_kind' => ServiceKind::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'typical_quantity' => 'decimal:2',
            'frequency_days' => 'integer',
            'tank_capacity_liters' => 'integer',
            'requires_own_pump' => 'boolean',
            'price_per_liter' => 'decimal:4',
            'last_served_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function defaultDeliveryType(): BelongsTo
    {
        return $this->belongsTo(DeliveryType::class, 'default_delivery_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeKind(Builder $query, ?string $kind): Builder
    {
        return $kind ? $query->where('service_kind', $kind) : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'ilike', $like)
            ->orWhere('tax_id', 'ilike', $like)
            ->orWhere('external_ref', 'ilike', $like)
            ->orWhere('city', 'ilike', $like)
            ->orWhere('phone', 'ilike', $like)
            ->orWhere('contact_name', 'ilike', $like));
    }

    /** Repartos anteriores emparejados por CIF (si lo hay) o por nombre exacto — no hay FK todavía. */
    public function pastStops(): Builder
    {
        return RouteStop::query()
            ->with(['route.driver.user', 'deliveryType', 'deliveryNote'])
            ->join('routes', 'routes.id', '=', 'route_stops.route_id')
            ->when(
                $this->tax_id,
                fn (Builder $q) => $q->where('route_stops.customer_tax_id', $this->tax_id),
                fn (Builder $q) => $q->where('route_stops.customer_name', $this->name),
            )
            ->orderByDesc('routes.route_date')
            ->select('route_stops.*');
    }

    /** Fecha estimada del próximo reparto = última servida + periodicidad. */
    public function nextDeliveryOn(): ?Carbon
    {
        return ($this->last_served_on && $this->frequency_days)
            ? $this->last_served_on->copy()->addDays($this->frequency_days)
            : null;
    }

    /** ¿Le toca reparto? (estimación vencida o para hoy). */
    public function isDeliveryDue(): bool
    {
        $next = $this->nextDeliveryOn();

        return $next !== null && ($next->isToday() || $next->isPast());
    }

    /** "semanal", "quincenal", "cada 10 días"… */
    public function frequencyLabel(): string
    {
        return match (true) {
            $this->frequency_days === null => 'Bajo demanda',
            $this->frequency_days === 7 => 'Semanal',
            $this->frequency_days === 14 => 'Quincenal',
            $this->frequency_days === 15 => 'Quincenal',
            $this->frequency_days === 30, $this->frequency_days === 31 => 'Mensual',
            default => "Cada {$this->frequency_days} días",
        };
    }
}
