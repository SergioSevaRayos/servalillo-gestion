<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Enums\DeliveryNoteStatus;
use App\Enums\OdometerKind;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\DeliveryType;
use App\Models\Device;
use App\Models\Driver;
use App\Models\ErrorLog;
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
use OwenIt\Auditing\Models\Audit;

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
                'liter_meter' => [1458320, 892100, 640540, 1120890][$i],
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

        // --- Rutas de ejemplo (hoy / ayer, para el tablero) ----------------
        $this->seedRoute($drivers[0], Truck::where('code', 'C-01')->first(), $today, RouteStatus::InProgress, $gasoleo, $admin);
        $this->seedRoute($drivers[1], Truck::where('code', 'C-02')->first(), $today, RouteStatus::Published, $agua, $admin);
        $this->seedRoute($drivers[2], Truck::where('code', 'C-03')->first(), $today->copy()->subDay(), RouteStatus::Completed, $gasoleo, $admin);

        // --- Historial (para el panel estadístico del Bloque 5) ------------
        $this->seedHistory($drivers, [$gasoleo, $agua], $admin);

        // --- Auditoría + errores (para el panel de Mantenimiento del Bloque 6) ---
        $this->seedMaintenanceData($admin, $maintenance);

        // --- Albaranes (Bloque 8) ------------------------------------------
        $this->seedDeliveryNotes($admin);

        // --- Clientes (Bloque 9) ------------------------------------------
        $this->seedClients([$gasoleo, $agua]);

        // --- Viajes de ejemplo (para el filtro del tablero) ---------------
        $this->seedTrips($today, $admin);

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
                'liter_meter_start' => in_array($status, [RouteStatus::InProgress, RouteStatus::Completed]) ? $truck->liter_meter : null,
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

            // Contador de litros: la ruta de ayer cerró con un pequeño descuadre (ejemplo para el panel).
            $delivered = $route->stops()->where('status', RouteStopStatus::Completed->value)->sum('delivered_quantity');
            $end = (int) ($truck->liter_meter + $delivered + 4);
            $route->update([
                'liter_meter_end' => $end,
                'liter_discrepancy_note' => 'Se soltó la manguera del depósito y se derramaron unos 4 L por el suelo.',
            ]);
            $truck->update(['liter_meter' => $end]);
        }
    }

    /**
     * Genera ~90 días de rutas pasadas con paradas completadas/falladas, cantidades y lecturas
     * de odómetro, para que el panel estadístico (Bloque 5) tenga algo que mostrar.
     *
     * @param  array<int, Driver>  $drivers
     * @param  array<int, DeliveryType>  $types
     */
    private function seedHistory(array $drivers, array $types, User $creator): void
    {
        // Idempotencia básica: si ya hay rutas de hace más de una semana, el historial ya está sembrado.
        if (Route::where('route_date', '<', Carbon::today()->subDays(7))->exists()) {
            return;
        }

        $trucks = Truck::orderBy('code')->get()->values();
        $odometerCursor = $trucks->mapWithKeys(fn (Truck $t) => [$t->id => $t->odometer - 15000]);
        $literCursor = $trucks->mapWithKeys(fn (Truck $t) => [$t->id => max(0, $t->liter_meter - 400000)]);

        for ($daysAgo = 90; $daysAgo >= 2; $daysAgo--) {
            $date = Carbon::today()->subDays($daysAgo);

            if ($date->isSunday()) {
                continue;
            }

            foreach ($trucks as $i => $truck) {
                // No todos los camiones salen todos los días.
                if (fake()->boolean($date->isSaturday() ? 40 : 82) === false) {
                    continue;
                }

                $driver = $drivers[$i % count($drivers)];
                $type = $types[$i % count($types)];
                $cancelled = fake()->boolean(6);

                $route = Route::create([
                    'code' => 'R-'.$date->format('Ymd').'-'.$truck->code,
                    'route_date' => $date->toDateString(),
                    'truck_id' => $truck->id,
                    'driver_id' => $driver->id,
                    'status' => $cancelled ? RouteStatus::Cancelled : RouteStatus::Completed,
                    'name' => 'Ruta '.$date->isoFormat('D MMM').' · '.$truck->code,
                    'created_by' => $creator->id,
                    'started_at' => $cancelled ? null : $date->copy()->setTime(7, 15),
                    'completed_at' => $cancelled ? null : $date->copy()->setTime(fake()->numberBetween(14, 17), 30),
                ]);

                if ($cancelled) {
                    continue;
                }

                $stopCount = fake()->numberBetween(3, 7);
                for ($s = 1; $s <= $stopCount; $s++) {
                    $planned = fake()->numberBetween(2, 20) * 100;
                    $failed = fake()->boolean(9);
                    // Alguna entrega se queda algo corta respecto a lo planificado.
                    $delivered = $failed ? null : (fake()->boolean(80) ? $planned : $planned - fake()->numberBetween(1, 4) * 50);

                    RouteStop::create([
                        'route_id' => $route->id,
                        'position' => $s,
                        'customer_name' => fake()->company(),
                        'address' => fake()->streetAddress().', '.fake()->randomElement(['Santa Cruz', 'La Laguna', 'Tegueste', 'El Rosario']),
                        'latitude' => fake()->latitude(28.4, 28.55),
                        'longitude' => fake()->longitude(-16.5, -16.2),
                        'delivery_type_id' => $type->id,
                        'status' => $failed ? RouteStopStatus::Failed : RouteStopStatus::Completed,
                        'planned_quantity' => $planned,
                        'delivered_quantity' => $delivered,
                        'completed_at' => $failed ? null : $date->copy()->setTime(8 + intdiv($s, 2), ($s % 2) * 30),
                        'failure_reason' => $failed ? fake()->randomElement(['Cliente ausente', 'Acceso bloqueado', 'Pedido anulado en puerta']) : null,
                        'data' => $type->slug === 'gasoleo'
                            ? ['producto' => 'Gasóleo A', 'litros_pedido' => $planned, 'forma_pago' => 'Contado', 'requiere_bomba' => false]
                            : ['litros_pedido' => $planned, 'tipo_deposito' => 'Aljibe', 'potable' => true],
                    ]);
                }

                $start = $odometerCursor[$truck->id];
                $end = $start + fake()->numberBetween(60, 240);
                $odometerCursor[$truck->id] = $end;

                OdometerReading::create(['route_id' => $route->id, 'truck_id' => $truck->id, 'driver_id' => $driver->id, 'kind' => OdometerKind::Start->value, 'value' => $start, 'recorded_at' => $date->copy()->setTime(7, 10)]);
                OdometerReading::create(['route_id' => $route->id, 'truck_id' => $truck->id, 'driver_id' => $driver->id, 'kind' => OdometerKind::End->value, 'value' => $end, 'recorded_at' => $date->copy()->setTime(16, 5)]);

                // Contador de litros: avanza lo repartido + alguna merma ocasional.
                $meterStart = $literCursor[$truck->id];
                $repartido = (int) $route->stops()->where('status', RouteStopStatus::Completed->value)->sum('delivered_quantity');
                $merma = fake()->boolean(15) ? fake()->numberBetween(2, 12) : 0;
                $meterEnd = $meterStart + $repartido + $merma;
                $literCursor[$truck->id] = $meterEnd;
                $route->update([
                    'liter_meter_start' => $meterStart,
                    'liter_meter_end' => $meterEnd,
                    'liter_discrepancy_note' => $merma ? "Merma de {$merma} L: goteo en la manguera durante el reparto." : null,
                ]);
            }

            $trucks->each(fn (Truck $t) => $t->update(['liter_meter' => $literCursor[$t->id]]));
        }
    }

    /**
     * Auditoría y errores de ejemplo para el panel de Mantenimiento (Bloque 6).
     *
     * El auditing real está desactivado en consola (`config/audit.php` → `console => false`),
     * así que las filas de `audits` se insertan a mano imitando eventos web reales.
     */
    private function seedMaintenanceData(User $admin, User $maintenance): void
    {
        if (Audit::query()->exists()) {
            return;
        }

        $routes = Route::inRandomOrder()->limit(15)->get();
        $trucks = Truck::all();
        $actors = [$admin, $maintenance];

        foreach (range(1, 35) as $n) {
            $when = Carbon::now()->subDays(fake()->numberBetween(0, 25))->subMinutes(fake()->numberBetween(0, 1440));
            $actor = fake()->randomElement($actors);
            [$model, $old, $new] = fake()->randomElement([
                [$routes->random(), ['status' => 'draft'], ['status' => 'published']],
                [$routes->random(), ['name' => 'Ruta antigua'], ['name' => 'Ruta '.fake()->word()]],
                [$trucks->random(), ['odometer' => fake()->numberBetween(90000, 200000)], ['odometer' => fake()->numberBetween(200001, 320000)]],
                [$trucks->random(), ['is_active' => true], ['is_active' => false]],
            ]);
            $event = fake()->randomElement(['updated', 'updated', 'updated', 'created', 'deleted']);

            Audit::create([
                'user_type' => User::class,
                'user_id' => $actor->id,
                'event' => $event,
                'auditable_type' => $model::class,
                'auditable_id' => $model->id,
                'old_values' => $event === 'created' ? [] : $old,
                'new_values' => $event === 'deleted' ? [] : $new,
                'url' => 'http://localhost:8000/'.fake()->randomElement(['rutas', 'camiones', 'rutas/listado']),
                'ip_address' => fake()->ipv4(),
                'user_agent' => 'Mozilla/5.0 (Seed)',
                'tags' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
        }

        $exceptions = [
            ['Illuminate\Database\QueryException', 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "routes_truck_id_route_date_unique"', 'app/Livewire/Routes/Board.php', 118],
            ['ErrorException', 'Undefined array key "litros_pedido"', 'app/Services/DeliveryTypeSchemaValidator.php', 64],
            ['Symfony\Component\HttpKernel\Exception\HttpException', 'Server Error', 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php', 1220],
            ['RuntimeException', 'Reverb connection refused on 127.0.0.1:8080', 'app/Events/GpsPositionReceived.php', 41],
            ['TypeError', 'App\\Services\\FleetStatsService::report(): Argument #1 ($from) must be of type Carbon\\Carbon, null given', 'app/Livewire/Dashboard/Index.php', 44],
        ];

        foreach (range(1, 9) as $n) {
            [$class, $message, $file, $line] = fake()->randomElement($exceptions);
            $when = Carbon::now()->subDays(fake()->numberBetween(0, 40))->subMinutes(fake()->numberBetween(0, 1440));

            ErrorLog::create([
                'level' => 'error',
                'message' => $message,
                'exception_class' => $class,
                'file' => $file,
                'line' => $line,
                'context' => ['trace' => [
                    ['file' => $file, 'line' => $line, 'function' => 'handle'],
                    ['file' => 'vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php', 'line' => 96, 'function' => 'handleUpdate'],
                    ['file' => 'public/index.php', 'line' => 17, 'function' => 'run'],
                ]],
                'user_id' => fake()->boolean(70) ? fake()->randomElement($actors)->id : null,
                'url' => 'http://localhost:8000/'.fake()->randomElement(['dashboard', 'rutas', 'livewire/update']),
                'method' => fake()->randomElement(['GET', 'POST']),
                'occurred_at' => $when,
            ]);
        }
    }

    /**
     * Un albarán por cada parada completada del historial (Bloque 8). Estados variados;
     * no se genera PDF real ni se encola nada (es un seeder).
     */
    private function seedDeliveryNotes(User $creator): void
    {
        if (DeliveryNote::query()->exists()) {
            return;
        }

        $completed = RouteStop::query()
            ->where('status', RouteStopStatus::Completed->value)
            ->whereDoesntHave('deliveryNote')
            ->with('route')
            ->get();

        $seq = 0;

        foreach ($completed as $stop) {
            $seq++;
            $channel = fake()->boolean(70) ? 'email' : 'physical';
            $issuedAt = $stop->completed_at ?? $stop->route?->route_date ?? now();

            $status = $channel === 'email'
                ? fake()->randomElement([
                    DeliveryNoteStatus::Sent,
                    DeliveryNoteStatus::Sent,
                    DeliveryNoteStatus::Generated,
                    DeliveryNoteStatus::Failed,
                ])
                : DeliveryNoteStatus::DeliveredPhysically;

            DeliveryNote::create([
                'route_stop_id' => $stop->id,
                'number' => 'ALB-'.Carbon::parse($issuedAt)->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                'issued_at' => $issuedAt,
                'customer_snapshot' => [
                    'name' => $stop->customer_name,
                    'tax_id' => strtoupper(fake()->bothify('?########')),
                    'address' => $stop->address,
                ],
                'delivered_quantity' => $stop->delivered_quantity,
                'signer_name' => fake()->name(),
                'delivery_channel' => $channel,
                'recipient_email' => $channel === 'email' ? fake()->safeEmail() : null,
                'status' => $status,
                'failure_reason' => $status === DeliveryNoteStatus::Failed ? 'Dirección de correo rechazada por el servidor' : null,
                'sent_at' => $status === DeliveryNoteStatus::Sent ? $issuedAt : null,
                'delivered_at' => $status === DeliveryNoteStatus::DeliveredPhysically ? $issuedAt : null,
                'created_by' => $creator->id,
            ]);
        }
    }

    /**
     * Clientes (Bloque 9). Se crean ~45 clientes y luego se reasigna la mayor parte de las
     * paradas del historial a un cliente al azar (copiando nombre + CIF), para que el
     * "histórico por cliente" — emparejado por CIF — tenga varios repartos por cliente.
     *
     * @param  array<int, DeliveryType>  $types
     */
    private function seedClients(array $types): void
    {
        if (Client::query()->exists()) {
            return;
        }

        $cities = ['Santa Cruz de Tenerife', 'La Laguna', 'La Orotava', 'Adeje', 'Granadilla', 'Arona', 'Güímar', 'Tegueste'];

        $clients = collect(range(1, 45))->map(fn (int $seq) => Client::create([
            'external_ref' => 'AX-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'name' => fake()->randomElement([fake()->company(), fake()->lastName().' e Hijos', 'Comunidad '.fake()->lastName()]),
            'tax_id' => strtoupper(fake()->unique()->bothify('?########')),
            'client_type' => fake()->randomElement(ClientType::cases())->value,
            // Casi todos son de reparto; unos pocos de "viaje" para que el filtro tenga contenido.
            'service_kind' => fake()->boolean(22) ? ServiceKind::Viaje->value : ServiceKind::Reparto->value,
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('6## ### ###'),
            'email' => fake()->optional(0.6)->safeEmail(),
            'address' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('380##'),
            'city' => fake()->randomElement($cities),
            'province' => 'Santa Cruz de Tenerife',
            'latitude' => fake()->latitude(28.0, 28.6),
            'longitude' => fake()->longitude(-16.9, -16.1),
            'default_delivery_type_id' => fake()->randomElement($types)->id,
            'typical_quantity' => fake()->randomElement([300, 500, 800, 1000, 1500, 2000]),
            'frequency_days' => fake()->optional(0.75)->randomElement([7, 14, 15, 21, 30, 45]),
            'tank_capacity_liters' => fake()->optional(0.7)->randomElement([1000, 2000, 3000, 5000]),
            'requires_own_pump' => fake()->boolean(20),
            'preferred_channel' => fake()->randomElement(['email', 'physical']),
            'payment_terms' => fake()->randomElement(['Contado', 'Transferencia 30 días', 'Domiciliado']),
            'last_served_on' => fake()->dateTimeBetween('-50 days', '-1 day'),
            'access_notes' => fake()->optional(0.4)->randomElement([
                'Portón azul al fondo del camino, llamar antes de llegar.',
                'El depósito está tras el garaje; acceso por la parte trasera.',
                'Carretera estrecha, no entra el camión grande.',
            ]),
            'is_active' => fake()->boolean(93),
        ]));

        // Reasigna ~75% de las paradas del historial a un cliente (nombre + CIF).
        RouteStop::query()->whereNotNull('route_id')->inRandomOrder()->get()->each(function (RouteStop $stop) use ($clients) {
            if (fake()->boolean(75)) {
                $client = $clients->random();
                $stop->update(['customer_name' => $client->name, 'customer_tax_id' => $client->tax_id]);
            }
        });

        // Clientes sin historial todavía.
        Client::factory()->count(12)->create();
    }

    /** Una ruta de "viaje" para hoy + backlog, para que el filtro Reparto/Viajes del tablero tenga contenido. */
    private function seedTrips(Carbon $today, User $creator): void
    {
        if (Route::where('service_kind', ServiceKind::Viaje->value)->exists()) {
            return;
        }

        $truck = Truck::where('code', 'C-04')->first();
        $driver = Driver::query()->inRandomOrder()->first();
        $clients = Client::where('service_kind', ServiceKind::Viaje->value)->take(6)->get();

        if (! $truck || ! $driver || $clients->isEmpty()) {
            return;
        }

        $route = Route::updateOrCreate(
            ['truck_id' => $truck->id, 'route_date' => $today->toDateString()],
            [
                'code' => 'V-'.$today->format('Ymd').'-'.$truck->code,
                'driver_id' => $driver->id,
                'status' => RouteStatus::Published,
                'service_kind' => ServiceKind::Viaje->value,
                'name' => 'Viajes '.$today->isoFormat('D MMM'),
                'created_by' => $creator->id,
            ]
        );

        foreach ($clients as $i => $client) {
            RouteStop::updateOrCreate(
                ['customer_name' => $client->name, 'service_kind' => ServiceKind::Viaje->value],
                [
                    'route_id' => $i < 3 ? $route->id : null, // el resto queda en "Sin asignar"
                    'position' => $i + 1,
                    'customer_tax_id' => $client->tax_id,
                    'address' => $client->address,
                    'latitude' => $client->latitude,
                    'longitude' => $client->longitude,
                    'contact_name' => $client->contact_name,
                    'contact_phone' => $client->phone,
                    'status' => RouteStopStatus::Pending,
                    'data' => [],
                ]
            );
        }
    }
}
