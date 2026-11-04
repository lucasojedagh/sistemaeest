<?php

// Request de validacion para actualizar el horario de un alumno. Define las reglas para las celdas del horario.

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAlumnoHorarioRequest extends FormRequest
{
    // Determina si el usuario esta autorizado para realizar esta solicitud (actualmente siempre true).
    public function authorize(): bool
    {
        return true; // ajustá si usás políticas/autorización
    }

    // Define las reglas de validacion para las celdas del horario: dia, bloque, materia y tipo de marcado.
    public function rules(): array
    {
        return [
            'celdas' => ['array'],
            'celdas.*.dia' => ['required','integer','min:1','max:7'],
            'celdas.*.bloque' => ['required','integer','min:1'],
            'celdas.*.materia_id' => ['nullable','integer','exists:materias,id'],
            'celdas.*.materia_nombre' => ['nullable','string','max:255'],
            'celdas.*.marcado' => ['nullable','in:normal,recursando,intensificacion'],
        ];
    }
}
