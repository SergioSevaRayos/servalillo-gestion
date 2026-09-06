<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class DeliveryType extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'is_active', 'field_schema'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'field_schema' => 'array',
        ];
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }

    /** @return array<int, array<string, mixed>> */
    public function fields(): array
    {
        return $this->field_schema ?? [];
    }
}
