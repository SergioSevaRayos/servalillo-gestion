<?php

namespace Database\Seeders;

use App\Models\DeliveryType;
use Illuminate\Database\Seeder;

/**
 * Tipos de reparto con su `field_schema`. Idempotente (`updateOrCreate` por slug).
 *
 * En **producción** (`ProductionSeeder` → `run()`) solo se siembra **agua**: la flota
 * reparte agua. El tipo `gasóleo` solo existe en el seed de **desarrollo** (`seed()`),
 * para tener datos históricos variados en el panel de estadísticas.
 */
class DeliveryTypeSeeder extends Seeder
{
    public static function water(): DeliveryType
    {
        return DeliveryType::updateOrCreate(['slug' => 'agua'], [
            'name' => 'Suministro de agua',
            'description' => 'Llenado de depósitos y aljibes.',
            'is_active' => true,
            'field_schema' => [
                ['key' => 'tipo_deposito', 'label' => 'Tipo de depósito', 'type' => 'select', 'required' => false,
                    'options' => ['Aljibe', 'Piscina', 'Depósito agrícola', 'Otro']],
                ['key' => 'potable', 'label' => 'Agua potable', 'type' => 'boolean', 'required' => false],
                ['key' => 'observaciones', 'label' => 'Observaciones', 'type' => 'textarea', 'required' => false],
            ],
        ]);
    }

    public static function gasoleo(): DeliveryType
    {
        return DeliveryType::updateOrCreate(['slug' => 'gasoleo'], [
            'name' => 'Reparto de gasóleo',
            'description' => 'Entrega de gasóleo A/B/C a domicilio o industria.',
            'is_active' => true,
            'field_schema' => [
                ['key' => 'producto', 'label' => 'Producto', 'type' => 'select', 'required' => true,
                    'options' => ['Gasóleo A', 'Gasóleo B', 'Gasóleo C']],
                ['key' => 'litros_pedido', 'label' => 'Litros pedidos', 'type' => 'number', 'required' => true, 'unit' => 'L', 'min' => 0],
                ['key' => 'precio_litro', 'label' => 'Precio / litro', 'type' => 'number', 'required' => false, 'unit' => '€'],
                ['key' => 'forma_pago', 'label' => 'Forma de pago', 'type' => 'select', 'required' => false,
                    'options' => ['Contado', 'Transferencia', 'Domiciliado']],
                ['key' => 'requiere_bomba', 'label' => 'Requiere bomba propia', 'type' => 'boolean', 'required' => false],
            ],
        ]);
    }

    /**
     * Seed de desarrollo: ambos tipos.
     *
     * @return array{gasoleo: DeliveryType, agua: DeliveryType}
     */
    public static function seed(): array
    {
        return ['gasoleo' => self::gasoleo(), 'agua' => self::water()];
    }

    /**
     * Seed de producción: solo agua.
     */
    public function run(): void
    {
        self::water();
    }
}
