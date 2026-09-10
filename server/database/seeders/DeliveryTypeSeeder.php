<?php

namespace Database\Seeders;

use App\Models\DeliveryType;
use Illuminate\Database\Seeder;

/**
 * Los dos tipos de reparto base (gasóleo y agua) con su `field_schema`. Idempotente
 * (`updateOrCreate` por slug). Se usa tanto en el seed de desarrollo como en el de
 * producción (`ProductionSeeder`), que solo siembra roles + estos tipos.
 */
class DeliveryTypeSeeder extends Seeder
{
    /**
     * @return array{gasoleo: DeliveryType, agua: DeliveryType}
     */
    public static function seed(): array
    {
        $gasoleo = DeliveryType::updateOrCreate(['slug' => 'gasoleo'], [
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

        $agua = DeliveryType::updateOrCreate(['slug' => 'agua'], [
            'name' => 'Suministro de agua',
            'description' => 'Llenado de depósitos y aljibes.',
            'is_active' => true,
            'field_schema' => [
                ['key' => 'litros_pedido', 'label' => 'Litros pedidos', 'type' => 'number', 'required' => true, 'unit' => 'L', 'min' => 0],
                ['key' => 'tipo_deposito', 'label' => 'Tipo de depósito', 'type' => 'select', 'required' => false,
                    'options' => ['Aljibe', 'Piscina', 'Depósito agrícola', 'Otro']],
                ['key' => 'potable', 'label' => 'Agua potable', 'type' => 'boolean', 'required' => false],
                ['key' => 'observaciones', 'label' => 'Observaciones', 'type' => 'textarea', 'required' => false],
            ],
        ]);

        return ['gasoleo' => $gasoleo, 'agua' => $agua];
    }

    public function run(): void
    {
        self::seed();
    }
}
