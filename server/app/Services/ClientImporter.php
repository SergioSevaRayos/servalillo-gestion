<?php

namespace App\Services;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\DeliveryType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use League\Csv\Reader;

/**
 * Importa clientes desde un CSV exportado del Access de origen. Upsert por `external_ref`
 * (el código del cliente en Access). Pensado para una carga única al implantar el sistema:
 * `php artisan clientes:importar clientes.csv`.
 */
class ClientImporter
{
    /** Cabeceras (normalizadas: minúsculas, sin acentos) → campo del modelo. */
    private const HEADER_MAP = [
        'codigo' => 'external_ref', 'code' => 'external_ref', 'external_ref' => 'external_ref', 'id' => 'external_ref', 'ref' => 'external_ref',
        'nombre' => 'name', 'name' => 'name', 'razon social' => 'name', 'cliente' => 'name',
        'cif' => 'tax_id', 'nif' => 'tax_id', 'tax_id' => 'tax_id', 'dni' => 'tax_id',
        'tipo' => 'client_type', 'tipo cliente' => 'client_type',
        'contacto' => 'contact_name', 'persona de contacto' => 'contact_name', 'contact_name' => 'contact_name',
        'telefono' => 'phone', 'tlf' => 'phone', 'movil' => 'phone', 'phone' => 'phone', 'telefono 1' => 'phone',
        'telefono 2' => 'secondary_phone', 'telefono secundario' => 'secondary_phone', 'tlf 2' => 'secondary_phone',
        'email' => 'email', 'correo' => 'email', 'e-mail' => 'email',
        'direccion' => 'address', 'domicilio' => 'address', 'address' => 'address',
        'cp' => 'postal_code', 'codigo postal' => 'postal_code', 'postal_code' => 'postal_code',
        'poblacion' => 'city', 'localidad' => 'city', 'municipio' => 'city', 'ciudad' => 'city', 'city' => 'city',
        'provincia' => 'province', 'province' => 'province',
        'latitud' => 'latitude', 'lat' => 'latitude', 'latitude' => 'latitude',
        'longitud' => 'longitude', 'lon' => 'longitude', 'lng' => 'longitude', 'longitude' => 'longitude',
        'litros' => 'typical_quantity', 'litros habituales' => 'typical_quantity', 'consumo' => 'typical_quantity', 'cantidad' => 'typical_quantity',
        'periodicidad' => 'frequency_days', 'frecuencia' => 'frequency_days', 'dias' => 'frequency_days', 'frequency_days' => 'frequency_days',
        'capacidad' => 'tank_capacity_liters', 'deposito' => 'tank_capacity_liters', 'capacidad deposito' => 'tank_capacity_liters',
        'bomba' => 'requires_own_pump', 'bomba propia' => 'requires_own_pump',
        'canal' => 'preferred_channel', 'canal albaran' => 'preferred_channel',
        'precio' => 'price_per_liter', 'precio litro' => 'price_per_liter',
        'forma de pago' => 'payment_terms', 'pago' => 'payment_terms',
        'tipo de reparto' => 'delivery_type', 'producto' => 'delivery_type',
        'ultimo reparto' => 'last_served_on', 'ultima fecha' => 'last_served_on', 'ultimo servicio' => 'last_served_on',
        'observaciones acceso' => 'access_notes', 'acceso' => 'access_notes', 'instrucciones' => 'access_notes',
        'observaciones' => 'notes', 'notas' => 'notes', 'notes' => 'notes',
        'activo' => 'is_active',
    ];

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $csv = Reader::createFromPath($path, 'r');
        $csv->setHeaderOffset(0);
        $csv->setDelimiter($this->sniffDelimiter($path));

        $deliveryTypes = DeliveryType::pluck('id', 'slug')
            ->merge(DeliveryType::pluck('id', 'name'))
            ->mapWithKeys(fn ($id, $key) => [$this->normalize($key) => $id]);

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($csv->getRecords() as $i => $row) {
            $line = $i + 1;
            $attrs = $this->mapRow($row, $deliveryTypes);

            if (blank($attrs['name'] ?? null)) {
                $result['errors'][] = "Fila {$line}: sin nombre de cliente, omitida.";
                $result['skipped']++;

                continue;
            }

            $validator = Validator::make($attrs, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'typical_quantity' => ['nullable', 'numeric', 'min:0'],
                'frequency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Fila {$line} ({$attrs['name']}): ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $existing = ! empty($attrs['external_ref'])
                ? Client::withTrashed()->where('external_ref', $attrs['external_ref'])->first()
                : null;

            if (! $dryRun) {
                if ($existing) {
                    $existing->fill($attrs)->save();
                    $existing->restore();
                } else {
                    Client::create($attrs);
                }
            }

            $result[$existing ? 'updated' : 'created']++;
        }

        return $result;
    }

    /** @param  array<string, mixed>  $deliveryTypes */
    private function mapRow(array $row, $deliveryTypes): array
    {
        $attrs = [];

        foreach ($row as $header => $value) {
            $field = self::HEADER_MAP[$this->normalize((string) $header)] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($field === null || $value === '' || $value === null) {
                continue;
            }

            $attrs[$field] = match ($field) {
                'client_type' => $this->parseType($value),
                'frequency_days' => $this->parseFrequency($value),
                'requires_own_pump' => $this->parseBool($value),
                'is_active' => $this->parseBool($value),
                'preferred_channel' => Str::contains($this->normalize($value), 'fisic') ? 'physical' : 'email',
                'last_served_on' => rescue(fn () => Carbon::parse($value)->toDateString(), null, false),
                'latitude', 'longitude', 'typical_quantity', 'price_per_liter' => $this->parseNumber($value),
                'tank_capacity_liters' => (int) $this->parseNumber($value),
                'delivery_type' => null, // se resuelve abajo
                default => $value,
            };

            if ($field === 'delivery_type') {
                $attrs['default_delivery_type_id'] = $deliveryTypes[$this->normalize($value)] ?? null;
                unset($attrs['delivery_type']);
            }
        }

        return $attrs;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->ascii()->squish()->value();
    }

    private function parseType(string $value): ?string
    {
        $n = $this->normalize($value);

        foreach (ClientType::cases() as $case) {
            if (str_contains($n, $case->value) || str_contains($n, $this->normalize($case->label()))) {
                return $case->value;
            }
        }

        return match (true) {
            str_contains($n, 'vecino') || str_contains($n, 'comunidad') => ClientType::Comunidad->value,
            str_contains($n, 'agri') || str_contains($n, 'finca') || str_contains($n, 'granja') => ClientType::Agricola->value,
            str_contains($n, 'obra') || str_contains($n, 'construc') => ClientType::Obra->value,
            str_contains($n, 'industri') || str_contains($n, 'fabrica') => ClientType::Industrial->value,
            str_contains($n, 'particular') || str_contains($n, 'domestic') => ClientType::Particular->value,
            default => ClientType::Empresa->value,
        };
    }

    private function parseFrequency(string $value): ?int
    {
        $n = $this->normalize($value);

        return match (true) {
            str_contains($n, 'seman') => 7,
            str_contains($n, 'quincen') => 15,
            str_contains($n, 'mensual') => 30,
            str_contains($n, 'bimestr') => 60,
            str_contains($n, 'demanda') || str_contains($n, 'puntual') => null,
            default => ((int) filter_var($value, FILTER_SANITIZE_NUMBER_INT)) ?: null,
        };
    }

    private function parseBool(string $value): bool
    {
        return in_array($this->normalize($value), ['si', 'sí', 's', '1', 'true', 'x', 'verdadero', 'activo'], true);
    }

    private function parseNumber(string $value): ?float
    {
        $value = trim($value);

        // "1.234,56" (formato español) → el punto es separador de miles, la coma decimal.
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ' '], '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(' ', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function sniffDelimiter(string $path): string
    {
        $firstLine = (string) fgets(fopen($path, 'r'));

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }
}
