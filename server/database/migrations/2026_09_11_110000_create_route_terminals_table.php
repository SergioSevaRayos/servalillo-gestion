<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Terminal vinculado a una ruta (petición del usuario): un teléfono/navegador puede "vincularse"
| a una ruta permanente visitando un enlace con un token largo (App\Http\Controllers\
| RouteTerminalController::pair()), que deja una cookie de por vida en ese navegador. Cuando
| CUALQUIER chofer inicia sesión desde un navegador con esa cookie, pasa a gestionar esa ruta ese
| día en vez de la suya propia (App\Services\RouteTerminalPairingService) — pensado para que un
| sustituto pueda cubrir la ruta de un compañero de baja sin que oficina tenga que reasignar nada
| a mano en el caso normal.
|
| `token` es el valor opaco que vive en la cookie (nunca el id de la ruta en crudo) — así es
| revocable sin depender de saber qué navegador físico lo tiene. `revoked_at` es una baja blanda:
| se conserva el histórico de terminales en vez de borrarlos.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['route_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_terminals');
    }
};
