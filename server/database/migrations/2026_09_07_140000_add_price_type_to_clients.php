<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El precio del cliente puede ser una tarifa fija por servicio o un precio por litro.
 * `price_per_liter` pasa a llamarse `price` (guarda el importe, sea del tipo que sea) y
 * `price_type` dice cómo interpretarlo. Los que ya tenían precio quedan como 'per_liter'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('price_per_liter', 'price');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('price_type', 20)->nullable()->after('price');
        });

        DB::table('clients')->whereNotNull('price')->update(['price_type' => 'per_liter']);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('price_type');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('price', 'price_per_liter');
        });
    }
};
