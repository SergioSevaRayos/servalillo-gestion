<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Una ruta es un camión + chofer, y aparece todos los días tenga viajes o no" (petición del
 * usuario tras ver en producción que la asignación permanente generaba una fila nueva por
 * día). Reparte lo que hoy es una sola tabla `routes` en dos:
 *
 * - `routes` (nueva) = la ficha permanente camión+chofer, antes `truck_assignments`.
 * - `route_days` = la actividad de esa ruta un día concreto (paradas, jornada, litros),
 *   antes `routes`. `route_stops.route_id` / `odometer_readings.route_id` /
 *   `gps_positions.route_id` siguen apuntando aquí, sin cambiar de nombre de columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', fn (Blueprint $table) => $table->dropForeign(['route_id']));
        Schema::table('odometer_readings', fn (Blueprint $table) => $table->dropForeign(['route_id']));
        Schema::table('gps_positions', fn (Blueprint $table) => $table->dropForeign(['route_id']));

        Schema::rename('routes', 'route_days');
        Schema::rename('truck_assignments', 'routes');

        Schema::table('routes', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            // Nombre explícito: el `routes` viejo (ahora `route_days`) ya dejó un índice físico
            // llamado "routes_service_kind_index" que Postgres no renombra al hacer el rename.
            $table->string('service_kind', 20)->default('reparto');
            $table->index('service_kind', 'routes_permanent_service_kind_index');
        });

        Schema::table('route_days', function (Blueprint $table) {
            $table->dropUnique('routes_truck_id_route_date_unique');
            $table->foreignId('route_id')->nullable()->after('id');
        });

        $this->backfillRouteId();

        Schema::table('route_days', function (Blueprint $table) {
            $table->foreignId('route_id')->nullable(false)->change();
            $table->foreign('route_id')->references('id')->on('routes')->restrictOnDelete();
            $table->unique(['route_id', 'route_date']);
        });

        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('route_days')->nullOnDelete();
        });
        Schema::table('odometer_readings', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('route_days')->cascadeOnDelete();
        });
        Schema::table('gps_positions', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('route_days')->nullOnDelete();
        });
    }

    /**
     * Empareja cada `route_days` huérfano con la ruta permanente de su mismo camión cuyo
     * rango de vigencia cubra esa fecha. Si ninguna cubre esa fecha (instalaciones con
     * historial suelto, no el caso de hoy en producción: la asignación ya creada cubre las
     * 6 fechas reales existentes), se crea una ruta "histórica" por cada combinación
     * (truck_id, driver_id) encontrada entre los huérfanos restantes, para que ningún día
     * se quede sin ruta permanente detrás.
     */
    private function backfillRouteId(): void
    {
        DB::statement(<<<'SQL'
            UPDATE route_days AS rd
            SET route_id = matched.id
            FROM (
                SELECT DISTINCT ON (rd2.id) rd2.id AS route_day_id, r.id
                FROM route_days rd2
                JOIN routes r ON r.truck_id = rd2.truck_id
                    AND r.valid_from <= rd2.route_date
                    AND (r.valid_until IS NULL OR r.valid_until >= rd2.route_date)
                WHERE rd2.route_id IS NULL
                ORDER BY rd2.id, r.valid_from DESC
            ) AS matched
            WHERE rd.id = matched.route_day_id
        SQL);

        $orphanGroups = DB::table('route_days')
            ->whereNull('route_id')
            ->select('truck_id', 'driver_id')
            ->selectRaw('min(route_date) as min_date, max(route_date) as max_date')
            ->groupBy('truck_id', 'driver_id')
            ->get();

        foreach ($orphanGroups as $group) {
            $routeId = DB::table('routes')->insertGetId([
                'truck_id' => $group->truck_id,
                'driver_id' => $group->driver_id,
                'service_kind' => 'reparto',
                'valid_from' => $group->min_date,
                'valid_until' => $group->max_date,
                'name' => 'Ruta histórica (generada automáticamente)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('route_days')
                ->whereNull('route_id')
                ->where('truck_id', $group->truck_id)
                ->where('driver_id', $group->driver_id)
                ->update(['route_id' => $routeId]);
        }
    }

    public function down(): void
    {
        Schema::table('route_stops', fn (Blueprint $table) => $table->dropForeign(['route_id']));
        Schema::table('odometer_readings', fn (Blueprint $table) => $table->dropForeign(['route_id']));
        Schema::table('gps_positions', fn (Blueprint $table) => $table->dropForeign(['route_id']));

        Schema::table('route_days', function (Blueprint $table) {
            $table->dropUnique(['route_id', 'route_date']);
            $table->dropForeign(['route_id']);
            $table->dropColumn('route_id');
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['name', 'notes', 'service_kind']);
        });

        Schema::rename('routes', 'truck_assignments');
        Schema::rename('route_days', 'routes');

        Schema::table('routes', function (Blueprint $table) {
            $table->unique(['truck_id', 'route_date']);
        });

        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
        });
        Schema::table('odometer_readings', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->cascadeOnDelete();
        });
        Schema::table('gps_positions', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
        });
    }
};
