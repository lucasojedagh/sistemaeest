<?php

namespace Database\Seeders;

use App\Dominios\Cursos\Materia;
use App\Dominios\Profesores\Profesor;
use Illuminate\Database\Seeder;

class ProfesorSeeder extends Seeder
{
    public function run(): void
    {
        // Asegurate de tener materias activas cargadas (por MateriaController o seeder previo)
        $m = fn($anio,$nombre) => Materia::where('anio',$anio)->where('nombre',$nombre)->where('activo',true)->first()?->id;

        $p1 = Profesor::create(['nombre'=>'María','apellido'=>'López','email'=>'mlopez@escuela.edu','estado'=>'Activo','suplente'=>false]);
        $p1->asignaciones()->createMany([
            ['anio'=>1,'division'=>'1','materia_id'=>$m(1,'Lengua')],
            ['anio'=>2,'division'=>'2','materia_id'=>$m(2,'Lengua')],
        ]);

        $p2 = Profesor::create(['nombre'=>'Juan','apellido'=>'Pérez','email'=>'jperez@escuela.edu','estado'=>'Activo','suplente'=>true]);
        $p2->asignaciones()->createMany([
            ['anio'=>4,'division'=>'3','materia_id'=>$m(4,'Matemática')],
            ['anio'=>5,'division'=>'2','materia_id'=>$m(5,'Matemática')],
        ]);
    }
}

