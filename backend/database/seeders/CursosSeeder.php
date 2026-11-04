<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\ParametroDivision;

class CursosSeeder extends Seeder
{
    public function run(): void
    {
        $divs = ParametroDivision::where('activo', true)->get(['anio','division']);
        foreach ($divs as $d) {
            Curso::firstOrCreate(
                ['anio'=>$d->anio, 'division'=>$d->division],
                ['activo'=>true]
            );
        }
    }
}
