<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_stop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->timestamp('issued_at');

            // Snapshot congelado del cliente en el momento de emitir el albarán
            $table->jsonb('customer_snapshot')->default('{}');
            $table->decimal('delivered_quantity', 12, 2)->nullable();
            $table->unsignedInteger('odometer_reading')->nullable();

            $table->string('signature_path')->nullable();
            $table->string('signer_name')->nullable();

            // Canal como string (no enum): "email" | "physical" | futuro "whatsapp"...
            $table->string('delivery_channel', 30);
            $table->string('recipient_email')->nullable();

            $table->string('pdf_path')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('failure_reason')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['delivery_channel', 'status']);
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
