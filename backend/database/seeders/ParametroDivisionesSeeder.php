<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParametroDivisionesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            1 => ['1','2','3','4','5','6','7','8','9'],
            2 => ['1','2','3','4','5','6','7','8','9'],
            3 => ['1','2','3','4','5','6','7','8','9'],
            4 => ['1','2','3','4','5','6','7','8','9'],
            5 => ['1','2','3','4','5','6','7','8','9'],
            6 => ['1','2','3','4','5','6','7','8','9'],
            7 => ['1','2','3','4','5','6','7','8','9'],
        ];

        foreach ($defaults as $anio => $divs) {
            foreach ($divs as $d) {
                DB::table('parametro_divisiones')->updateOrInsert(
                    ['anio' => $anio, 'division' => $d],
                    ['activo' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }
}


