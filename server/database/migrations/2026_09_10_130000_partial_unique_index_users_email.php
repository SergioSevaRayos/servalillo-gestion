<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.email` tenía un `unique()` normal, que la BD sigue aplicando aunque el usuario esté
 * borrado (SoftDeletes) — un email nunca se podía reutilizar tras borrar esa cuenta, ni
 * aunque la validación de Livewire dijera que sí (comprobaba la tabla sin filtrar `deleted_at`
 * hasta el fix en UserForm/DriverForm). Se sustituye por un índice único parcial: solo exige
 * unicidad entre las cuentas NO borradas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
