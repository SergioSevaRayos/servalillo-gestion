<?php

namespace Database\Seeders;

use App\Enums\OdometerKind;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Models\Device;
use App\Models\DeliveryType;
use App\Models\Driver;
use App\Models\OdometerReading;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Truck;
use App\Models\TruckAssignment;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $password = Hash::make('password');

        // --- Usuarios de gestión ---------------------------------------------
        $admin = User::updateOrCreate(
            ['email' => 'admin@servalillo.test'],
            ['name' => 'Ana Administradora', 'password' => $password, 'is_active' => true]
        );
        $admin->syncRoles('administrador');

        $maintenance = User::updateOrCreate(
            ['email' => 'soporte@servalillo.test'],
            ['name' => 'Mario Mantenimiento', 'password' => $password, 'is_active' => true]
        );
        $maintenance->syncRoles('mantenimiento');

        // --- Tipos de reparto (modelo flexible) -----------------------------
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

        // --- Camiones + choferes + dispositivos -----------------------------
        $today = Carbon::today();
        $drivers = [];

        $fleet = [
            ['code' => 'C-01', 'plate' => '1234-KJL', 'driver' => 'Pedro Ramírez', 'email' => 'pedro@servalillo.test'],
            ['code' => 'C-02', 'plate' => '5678-MNP', 'driver' => 'Lucía Gómez', 'email' => 'lucia@servalillo.test'],
            ['code' => 'C-03', 'plate' => '9012-RST', 'driver' => 'Carlos Díaz', 'email' => 'carlos@servalillo.test'],
            ['code' => 'C-04', 'plate' => '3456-VWX', 'driver' => 'Nadia El Amrani', 'email' => 'nadia@servalillo.test'],
        ];

        foreach ($fleet as $i => $row) {
            $truck = Truck::updateOrCreate(['code' => $row['code']], [
                'plate' => $row['plate'],
                'description' => 'Cisterna de reparto',
                'capacity_liters' => [13000, 16000, 20000, 13000][$i],
                'compartments' => [2, 3, 4, 2][$i],
                'model' => ['Volvo FH', 'Scania R450', 'MAN TGX', 'Iveco S-Way'][$i],
                'year' => [2019, 2021, 2022, 2020][$i],
                'odometer' => [285000, 142000, 96000, 210000][$i],
                'is_active' => true,
            ]);

            $user = User::updateOrCreate(['email' => $row['email']], [
                'name' => $row['driver'],
                'password' => $password,
                'is_active' => true,
            ]);
            $user->syncRoles('chofer');

            $driver = Driver::updateOrCreate(['user_id' => $user->id], [
                'employee_code' => sprintf('EMP-%03d', $i + 1),
                'license_number' => strtoupper(fake()->bothify('????######')),
                'license_expiry' => $today->copy()->addYears(2)->addMonths($i),
                'phone' => fake()->phoneNumber(),
                'is_active' => true,
            ]);
            $drivers[$i] = $driver;

            TruckAssignment::updateOrCreate(
                ['truck_id' => $truck->id, 'valid_until' => null],
                ['driver_id' => $driver->id, 'valid_from' => $today->copy()->subMonths(6)]
            );

            Device::updateOrCreate(['truck_id' => $truck->id], [
                'label' => 'Móvil '.$row['code'],
                'platform' => 'android',
                'install_identifier' => 'seed-device-'.$row['code'],
                'app_version' => '1.0.0',
                'is_active' => true,
            ]);
        }

        // --- Rutas de ejemplo ----------------------------------------------
        $this->seedRoute($drivers[0], Truck::where('code', 'C-01')->first(), $today, RouteStatus::InProgress, $gasoleo, $admin);
        $this->seedRoute($drivers[1], Truck::where('code', 'C-02')->first(), $today, RouteStatus::Published, $agua, $admin);
        $this->seedRoute($drivers[2], Truck::where('code', 'C-03')->first(), $today->copy()->subDay(), RouteStatus::Completed, $gasoleo, $admin);

        $this->command->info('Seed completo. Usuarios: admin@ / soporte@ / pedro@ ... contraseña "password".');
    }

    private function seedRoute(Driver $driver, Truck $truck, Carbon $date, RouteStatus $status, DeliveryType $type, User $creator): void
    {
        $route = Route::updateOrCreate(
            ['truck_id' => $truck->id, 'route_date' => $date->toDateString()],
            [
                'code' => 'R-'.$date->format('Ymd').'-'.$truck->code,
                'driver_id' => $driver->id,
                'status' => $status,
                'name' => 'Ruta '.$date->isoFormat('D MMM').' · '.$truck->code,
                'created_by' => $creator->id,
                'started_at' => in_array($status, [RouteStatus::InProgress, RouteStatus::Completed]) ? $date->copy()->setTime(7, 15) : null,
                'completed_at' => $status === RouteStatus::Completed ? $date->copy()->setTime(15, 40) : null,
            ]
        );

        $stops = [
            ['customer_name' => 'Panadería La Espiga', 'address' => 'C/ Mayor 12, Santa Cruz', 'lat' => 28.4636, 'lng' => -16.2518],
            ['customer_name' => 'Finca Los Almendros', 'address' => 'Camino Rural 4, La Laguna', 'lat' => 28.4874, 'lng' => -16.3159],
            ['customer_name' => 'Taller Hermanos Pérez', 'address' => 'Polígono Industrial 22', 'lat' => 28.4699, 'lng' => -16.2900],
            ['customer_name' => 'Comunidad Edificio Teide', 'address' => 'Avda. Islas Canarias 88', 'lat' => 28.4550, 'lng' => -16.2700],
        ];

        foreach ($stops as $i => $s) {
            $stopStatus = match (true) {
                $status === RouteStatus::Completed => RouteStopStatus::Completed,
                $status === RouteStatus::InProgress && $i === 0 => RouteStopStatus::Completed,
                default => RouteStopStatus::Pending,
            };

            RouteStop::updateOrCreate(
                ['route_id' => $route->id, 'position' => $i + 1],
                [
                    'customer_name' => $s['customer_name'],
                    'address' => $s['address'],
                    'latitude' => $s['lat'],
                    'longitude' => $s['lng'],
                    'delivery_type_id' => $type->id,
                    'status' => $stopStatus,
                    'planned_quantity' => [500, 1200, 300, 800][$i],
                    'delivered_quantity' => $stopStatus === RouteStopStatus::Completed ? [500, 1200, 300, 800][$i] : null,
                    'completed_at' => $stopStatus === RouteStopStatus::Completed ? $date->copy()->setTime(9 + $i, 0) : null,
                    'data' => $type->slug === 'gasoleo'
                        ? ['producto' => 'Gasóleo A', 'litros_pedido' => [500, 1200, 300, 800][$i], 'forma_pago' => 'Contado', 'requiere_bomba' => false]
                        : ['litros_pedido' => [500, 1200, 300, 800][$i], 'tipo_deposito' => 'Aljibe', 'potable' => true],
                ]
            );
        }

        if (in_array($status, [RouteStatus::InProgress, RouteStatus::Completed])) {
            OdometerReading::updateOrCreate(
                ['route_id' => $route->id, 'kind' => OdometerKind::Start->value],
                ['truck_id' => $truck->id, 'driver_id' => $driver->id, 'value' => $truck->odometer, 'recorded_at' => $date->copy()->setTime(7, 10)]
            );
        }

        if ($status === RouteStatus::Completed) {
            OdometerReading::updateOrCreate(
                ['route_id' => $route->id, 'kind' => OdometerKind::End->value],
                ['truck_id' => $truck->id, 'driver_id' => $driver->id, 'value' => $truck->odometer + 180, 'recorded_at' => $date->copy()->setTime(15, 35)]
            );
        }
    }
}
