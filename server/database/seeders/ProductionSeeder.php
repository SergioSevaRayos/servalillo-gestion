<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Lo único que se siembra en producción: roles + permisos y los tipos de reparto base.
 * NO ejecutar `DatabaseSeeder` en producción — genera datos de prueba con `fake()`
 * (que es una dependencia de dev) y crea usuarios `@servalillo.test`.
 *
 * Uso:  php artisan db:seed --class=ProductionSeeder --force
 *
 * El usuario administrador se crea aparte con `php artisan servalillo:crear-usuario`.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DeliveryTypeSeeder::class,
        ]);
    }
}
