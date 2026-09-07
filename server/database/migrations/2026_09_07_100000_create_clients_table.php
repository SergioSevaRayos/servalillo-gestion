<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes (Bloque 9). Módulo independiente: los repartos (`route_stops`) aún guardan los
 * datos del cliente denormalizados; el histórico por cliente se empareja por CIF/nombre.
 *
 * `external_ref` = código del cliente en el Access de origen; el importador
 * (`php artisan clientes:importar`) hace upsert por esa columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('external_ref')->nullable()->unique();

            // Identificación
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('client_type', 20)->nullable();

            // Contacto
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('secondary_phone')->nullable();
            $table->string('email')->nullable();

            // Ubicación
            $table->string('address')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Reparto habitual
            $table->foreignId('default_delivery_type_id')->nullable()->constrained('delivery_types')->nullOnDelete();
            $table->decimal('typical_quantity', 12, 2)->nullable();
            $table->unsignedSmallInteger('frequency_days')->nullable(); // null = bajo demanda
            $table->unsignedInteger('tank_capacity_liters')->nullable();
            $table->boolean('requires_own_pump')->default(false);
            $table->string('preferred_channel', 30)->nullable(); // email | physical
            $table->decimal('price_per_liter', 8, 4)->nullable();
            $table->string('payment_terms')->nullable();
            $table->date('last_served_on')->nullable();

            // Observaciones
            $table->text('access_notes')->nullable(); // acceso / instrucciones para el chofer
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tax_id');
            $table->index('city');
            $table->index(['is_active', 'name']);
            $table->index('frequency_days');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
