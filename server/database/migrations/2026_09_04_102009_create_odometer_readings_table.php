<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odometer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('truck_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10); // start | end
            $table->unsignedInteger('value');
            $table->timestamp('recorded_at');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odometer_readings');
    }
};
