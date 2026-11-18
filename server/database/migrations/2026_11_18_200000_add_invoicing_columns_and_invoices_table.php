<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cliente que siempre pide factura: marca sola la casilla del chofer al entregar.
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('always_invoice')->default(false)->after('payment_terms');
        });

        // Foto fija de lo que se pidió en ESA entrega (igual que counted_in_meter).
        Schema::table('route_stops', function (Blueprint $table) {
            $table->boolean('invoice_requested')->default(false)->after('counted_in_meter');
        });

        // Control de facturas que administración tiene que hacer a mano (fuera de la app).
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_stop_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('delivery_note_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->string('invoice_number')->nullable();
            // Datos fiscales congelados al pedirla: el listado no depende de ediciones posteriores.
            $table->jsonb('customer_snapshot')->nullable();
            $table->decimal('delivered_quantity', 12, 2)->nullable();
            $table->date('service_date')->nullable()->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');

        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropColumn('invoice_requested');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('always_invoice');
        });
    }
};
