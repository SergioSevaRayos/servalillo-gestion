<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('route_date');
            $table->foreignId('truck_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Regla de negocio validada: un camión = una ruta por día.
            $table->unique(['truck_id', 'route_date']);
            $table->index(['route_date', 'status']);
            $table->index(['driver_id', 'route_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
