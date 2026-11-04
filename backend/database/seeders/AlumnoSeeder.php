<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AlumnoSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'nombre' => 'Ana', 
                'apellido' => 'Gómez', 
                'dni' => '40123456',
                'anio' => 1, 
                'division' => '1', 
                'genero' => 'F',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Bruno', 
                'apellido' => 'Martínez', 
                'dni' => '39111222',
                'anio' => 3, 
                'division' => '3', 
                'genero' => 'M',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('alumnos')->insert($data);
    }
}