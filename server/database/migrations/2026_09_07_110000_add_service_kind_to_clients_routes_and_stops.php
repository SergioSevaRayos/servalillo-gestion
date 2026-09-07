<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['clients', 'routes', 'route_stops'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('service_kind', 20)->default('reparto')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['clients', 'routes', 'route_stops'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('service_kind');
            });
        }
    }
};
