<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /** Catálogo único de permisos del sistema. */
    public const PERMISSIONS = [
        'drivers.view', 'drivers.create', 'drivers.update', 'drivers.delete',
        'trucks.view', 'trucks.create', 'trucks.update', 'trucks.delete',
        'delivery_types.view', 'delivery_types.create', 'delivery_types.update', 'delivery_types.delete',
        'routes.view', 'routes.create', 'routes.update', 'routes.delete', 'routes.reorder_stops',
        'routes.view.own',
        'deliveries.complete', 'deliveries.record_signature',
        'odometer.record',
        'delivery_notes.view', 'delivery_notes.regenerate', 'delivery_notes.mark_delivered',
        'stats.view',
        'users.manage', 'roles.manage',
        'devices.manage',
        'audits.view', 'system_logs.view',
    ];

    public const CHOFER_PERMISSIONS = [
        'routes.view.own',
        'deliveries.complete',
        'deliveries.record_signature',
        'odometer.record',
        'delivery_notes.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach (self::PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $admin = Role::findOrCreate('administrador', 'web');
            $maintenance = Role::findOrCreate('mantenimiento', 'web');
            $driver = Role::findOrCreate('chofer', 'web');

            // Administrador: todo salvo los paneles de soporte técnico.
            $admin->syncPermissions(array_diff(self::PERMISSIONS, ['audits.view', 'system_logs.view']));

            // Mantenimiento: acceso total (además tiene Gate::before, esto lo hace explícito).
            $maintenance->syncPermissions(self::PERMISSIONS);

            // Chofer: solo su operativa.
            $driver->syncPermissions(self::CHOFER_PERMISSIONS);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
