<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_positions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();
            $table->decimal('heading_deg', 6, 2)->nullable();
            $table->unsignedSmallInteger('battery_level')->nullable();
            $table->timestamp('recorded_at'); // reloj del dispositivo
            $table->timestamp('created_at')->useCurrent();

            $table->index(['truck_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_positions');
    }
};
