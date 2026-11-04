<?php

namespace Database\Seeders;

use App\Dominios\Usuarios\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // La clave inicial sale de ADMIN_PASSWORD (ver .env.example).
        // Si no está definida, se genera una aleatoria y se muestra UNA vez
        // en la salida del comando: anotarla, no queda guardada en el repo.
        $email    = env('ADMIN_EMAIL', 'admin@admin.com');
        $password = env('ADMIN_PASSWORD') ?: Str::random(16);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'     => 'Admin',
                'apellido' => 'Sistema',
                'password' => $password, // el cast 'hashed' del modelo la cifra
                'role'     => 'admin',
                'status'   => 'active',
            ]
        );

        if ($user->wasRecentlyCreated) {
            $this->command->info("Usuario admin creado: {$email} / {$password}");
        } else {
            $this->command->info("Usuario admin ya existía ({$email}): no se tocó su clave.");
        }
    }
}
