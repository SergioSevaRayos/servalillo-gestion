<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index(['truck_id', 'valid_until']);
            $table->index(['driver_id', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truck_assignments');
    }
};
