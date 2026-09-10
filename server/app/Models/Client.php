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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Client extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    /** ISO: 1 = lunes … 7 = domingo. */
    public const WEEKDAY_LABELS = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

    protected $fillable = [
        'external_ref', 'name', 'tax_id', 'client_type', 'service_kind', 'status',
        'contact_name', 'phone', 'secondary_phone', 'email',
        'address', 'postal_code', 'city', 'province', 'latitude', 'longitude',
        'water_type', 'typical_quantity', 'quantity_unit',
        'frequency_days', 'delivery_weekdays', 'schedule_starts_on', 'schedule_ends_on',
        'tank_capacity_liters', 'tank_distance_m',
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
            'delivery_weekdays' => 'array',
            'schedule_starts_on' => 'date',
            'schedule_ends_on' => 'date',
            'tank_capacity_liters' => 'integer',
            'tank_distance_m' => 'integer',
            'requires_own_pump' => 'boolean',
            'price' => 'decimal:4',
            'price_type' => PriceType::class,
            'last_served_on' => 'date',
            'is_active' => 'boolean',
        ];
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

    /**
     * Texto listo para copiar y mandar por WhatsApp a los encargados, con los datos de la
     * llamada de un pre-cliente recién registrado (solo las líneas que tengan dato).
     */
    public function prospectSummary(): string
    {
        $lines = ['📋 Nuevo pre-cliente (pendiente de valoración)', ''];

        $fields = [
            'Nombre' => $this->name,
            'Teléfono' => $this->phone,
            'Tipo de servicio' => $this->service_kind->label(),
            'Dirección' => $this->address,
            'Tipo de agua' => $this->water_type?->label(),
            'Cantidad habitual' => $this->quantityLabel(),
            'Distancia depósito–camión' => $this->tank_distance_m ? "{$this->tank_distance_m} m" : null,
            'Observaciones' => $this->notes,
        ];

        foreach ($fields as $label => $value) {
            if (filled($value)) {
                $lines[] = "{$label}: {$value}";
            }
        }

        return implode("\n", $lines);
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

    /** @return array<int, int> días ISO (1..7) en los que se reparte, ordenados. */
    public function deliveryWeekdays(): array
    {
        $days = array_values(array_filter(
            array_map('intval', $this->delivery_weekdays ?? []),
            fn (int $d) => $d >= 1 && $d <= 7,
        ));
        sort($days);

        return $days;
    }

    public function hasWeekdaySchedule(): bool
    {
        return $this->deliveryWeekdays() !== [];
    }

    /** ¿El calendario está vigente en esa fecha? (rango [desde, hasta], nulos = abierto). */
    public function scheduleActiveOn(Carbon $date): bool
    {
        if ($this->schedule_starts_on && $date->lt($this->schedule_starts_on->copy()->startOfDay())) {
            return false;
        }

        if ($this->schedule_ends_on && $date->gt($this->schedule_ends_on->copy()->endOfDay())) {
            return false;
        }

        return true;
    }

    /** ¿Toca reparto ese día concreto según los días de la semana configurados? */
    public function isScheduledOn(Carbon $date): bool
    {
        return $this->hasWeekdaySchedule()
            && in_array($date->dayOfWeekIso, $this->deliveryWeekdays(), true)
            && $this->scheduleActiveOn($date);
    }

    /** Fecha estimada del próximo reparto: por días de la semana o por "cada N días". */
    public function nextDeliveryOn(): ?Carbon
    {
        if ($this->hasWeekdaySchedule()) {
            $date = today();

            for ($i = 0; $i < 14; $i++) {
                if ($this->isScheduledOn($date)) {
                    return $date->copy();
                }
                $date->addDay();
            }

            return null; // el calendario ya terminó
        }

        return ($this->last_served_on && $this->frequency_days)
            ? $this->last_served_on->copy()->addDays($this->frequency_days)
            : null;
    }

    /** ¿Le toca reparto hoy? */
    public function isDeliveryDue(): bool
    {
        if ($this->hasWeekdaySchedule()) {
            return $this->isScheduledOn(today());
        }

        $next = $this->nextDeliveryOn();

        return $next !== null && ($next->isToday() || $next->isPast());
    }

    /** "L·X·V", "L·X·V · ene–mar", "Semanal", "cada 10 días"… */
    public function frequencyLabel(): string
    {
        if ($this->hasWeekdaySchedule()) {
            $days = implode('·', array_map(fn (int $d) => self::WEEKDAY_LABELS[$d], $this->deliveryWeekdays()));
            $range = match (true) {
                $this->schedule_starts_on && $this->schedule_ends_on => ' · '.$this->schedule_starts_on->isoFormat('MMM').'–'.$this->schedule_ends_on->isoFormat('MMM'),
                (bool) $this->schedule_ends_on => ' · hasta '.$this->schedule_ends_on->isoFormat('D MMM'),
                (bool) $this->schedule_starts_on => ' · desde '.$this->schedule_starts_on->isoFormat('D MMM'),
                default => '',
            };

            return $days.$range;
        }

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
