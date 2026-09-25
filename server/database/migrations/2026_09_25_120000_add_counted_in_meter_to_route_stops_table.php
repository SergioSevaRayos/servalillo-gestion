<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->boolean('counted_in_meter')->default(true)->after('delivered_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropColumn('counted_in_meter');
        });
    }
};
