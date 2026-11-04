<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */


    
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        $this->call([
            ParametroDivisionesSeeder::class,
            CursosSeeder::class,
        ]);

        $this->call(AlumnoSeeder::class);
        $this->call(DemoDataSeeder::class);

        // Último: necesita las materias que crea DemoDataSeeder (si corre
        // antes, las asignaciones quedan con materia_id=null).
        $this->call(ProfesorSeeder::class);
    }
}
