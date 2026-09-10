<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Alta de un usuario de gestión (administrador o mantenimiento) desde consola. Para el
 * primer admin tras el despliegue. Los choferes NO se crean aquí — van por `/chofers`,
 * que además crea su registro `Driver`.
 */
class CreateUser extends Command
{
    protected $signature = 'servalillo:crear-usuario
        {--name= : Nombre completo}
        {--email= : Email (debe ser único)}
        {--password= : Contraseña (mín. 10 caracteres)}
        {--rol= : administrador | mantenimiento}';

    protected $description = 'Crea un usuario de gestión y le asigna su rol.';

    private const ROLES = ['administrador', 'mantenimiento'];

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Nombre completo', required: true);
        $email = $this->option('email') ?: text('Email', required: true);
        $rol = $this->option('rol') ?: select('Rol', self::ROLES, default: 'administrador');
        $plain = $this->option('password') ?: password('Contraseña (mín. 10)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plain, 'rol' => $rol],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:10'],
                'rol' => ['required', 'in:'.implode(',', self::ROLES)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $plain, // el cast 'hashed' del modelo lo hashea
            'is_active' => true,
        ]);
        $user->syncRoles($rol);

        $this->info("Usuario #{$user->id} <{$email}> creado con rol '{$rol}'.");

        return self::SUCCESS;
    }
}
