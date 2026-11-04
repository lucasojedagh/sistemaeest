<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD INDEX users_api_token_id_idx (api_token, id)');

        DB::statement('ALTER TABLE alumno_calificaciones ADD INDEX ac_ciclo_materia_idx (ciclo_lectivo, materia_id, alumno_id)');

        DB::statement('ALTER TABLE alumno_recursandos ADD INDEX ar_alumno_materia_idx (alumno_id, materia_id)');

        DB::statement('ALTER TABLE alumno_intensificaciones ADD INDEX ai_alumno_materia_idx (alumno_id, materia_id)');

        DB::statement('ALTER TABLE horario_celdas ADD INDEX hc_curso_materia_dia_slot_idx (curso_id, materia_id, dia, slot_key)');

        DB::statement('ALTER TABLE profesor_bajas ADD INDEX pb_profesor_fecha_idx (profesor_id, created_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP INDEX users_api_token_id_idx');
        DB::statement('ALTER TABLE alumno_calificaciones DROP INDEX ac_ciclo_materia_idx');
        DB::statement('ALTER TABLE alumno_recursandos DROP INDEX ar_alumno_materia_idx');
        DB::statement('ALTER TABLE alumno_intensificaciones DROP INDEX ai_alumno_materia_idx');
        DB::statement('ALTER TABLE horario_celdas DROP INDEX hc_curso_materia_dia_slot_idx');
        DB::statement('ALTER TABLE profesor_bajas DROP INDEX pb_profesor_fecha_idx');
    }
};
