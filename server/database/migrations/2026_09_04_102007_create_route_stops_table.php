<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            // Datos del cliente / punto de entrega
            $table->string('customer_name');
            $table->string('customer_tax_id')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();

            $table->foreignId('delivery_type_id')->nullable()->constrained('delivery_types')->nullOnDelete();
            $table->string('status', 20)->default('pending');

            $table->timestamp('scheduled_window_start')->nullable();
            $table->timestamp('scheduled_window_end')->nullable();

            $table->decimal('planned_quantity', 12, 2)->nullable();
            $table->decimal('delivered_quantity', 12, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason')->nullable();

            // Valores de los campos definidos en delivery_types.field_schema
            $table->jsonb('data')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['route_id', 'position']);
            $table->index(['route_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};
