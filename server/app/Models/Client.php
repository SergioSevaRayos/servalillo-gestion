<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\PriceType;
use App\Enums\ServiceKind;
use App\Enums\WaterType;
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
        'external_ref', 'name', 'tax_id', 'client_type', 'service_kind', 'status',
        'contact_name', 'phone', 'secondary_phone', 'email',
        'address', 'postal_code', 'city', 'province', 'latitude', 'longitude',
        'water_type', 'default_delivery_type_id', 'typical_quantity', 'quantity_unit',
        'frequency_days', 'tank_capacity_liters', 'tank_distance_m',
        'requires_own_pump', 'preferred_channel', 'price', 'price_type', 'payment_terms', 'last_served_on',
        'access_notes', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'client_type' => ClientType::class,
            'service_kind' => ServiceKind::class,
            'status' => ClientStatus::class,
            'water_type' => WaterType::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'typical_quantity' => 'decimal:2',
            'frequency_days' => 'integer',
            'tank_capacity_liters' => 'integer',
            'tank_distance_m' => 'integer',
            'requires_own_pump' => 'boolean',
            'price' => 'decimal:4',
            'price_type' => PriceType::class,
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

    /** Solo clientes reales — excluye los "Pendiente valoración". */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('status', ClientStatus::Customer->value);
    }

    public function scopeKind(Builder $query, ?string $kind): Builder
    {
        return $kind ? $query->where('service_kind', $kind) : $query;
    }

    public function isProspect(): bool
    {
        return $this->status === ClientStatus::Prospect;
    }

    /**
     * Cantidad habitual para mostrar. En BD siempre en litros (`typical_quantity`);
     * `quantity_unit` recuerda cómo lo dijo el cliente → "3 m³ (3.000 L)" / "3.000 L" / null.
     */
    public function quantityLabel(): ?string
    {
        if ($this->typical_quantity === null) {
            return null;
        }

        $liters = (float) $this->typical_quantity;
        $litersFmt = number_format($liters, 0, ',', '.').' L';

        if ($this->quantity_unit === 'm3') {
            $m3 = $liters / 1000;
            $m3Fmt = rtrim(rtrim(number_format($m3, 2, ',', '.'), '0'), ',');

            return "{$m3Fmt} m³ ({$litersFmt})";
        }

        return $litersFmt;
    }

    /** "45,00 € (tarifa fija)" / "0,9500 €/L" / null. */
    public function priceLabel(): ?string
    {
        if ($this->price === null) {
            return null;
        }

        $type = $this->price_type ?? PriceType::PerLiter;
        $decimals = $type === PriceType::Fixed ? 2 : 4;
        $amount = number_format((float) $this->price, $decimals, ',', '.').$type->suffix();

        return $type === PriceType::Fixed ? "{$amount} ({$type->label()})" : $amount;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';
        $digits = preg_replace('/\D+/', '', $term);

        return $query->where(function (Builder $q) use ($like, $digits) {
            $q->where('name', 'ilike', $like)
                ->orWhere('tax_id', 'ilike', $like)
                ->orWhere('external_ref', 'ilike', $like)
                ->orWhere('city', 'ilike', $like)
                ->orWhere('contact_name', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('secondary_phone', 'ilike', $like);

            // Búsqueda por teléfono ignorando espacios, guiones y prefijos (632 307 329 == 632307329).
            if (strlen($digits) >= 3) {
                $q->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ['%'.$digits.'%'])
                    ->orWhereRaw("regexp_replace(coalesce(secondary_phone, ''), '[^0-9]', '', 'g') like ?", ['%'.$digits.'%']);
            }
        });
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
