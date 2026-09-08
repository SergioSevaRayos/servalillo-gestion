<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal de soporte (Bloque 12): administración abre incidencias/necesidades hacia
 * mantenimiento. Cada ticket tiene asunto, categoría, estado y un primer mensaje;
 * las respuestas de ambos lados viven en `support_ticket_replies`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('category', 20);            // enum App\Enums\SupportCategory
            $table->string('status', 20)->default('abierto'); // enum App\Enums\SupportStatus
            $table->text('body');
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'last_reply_at']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
