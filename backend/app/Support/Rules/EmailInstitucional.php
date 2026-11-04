<?php

namespace App\Support\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// El correo de una cuenta tiene que ser institucional (@abc.gob.ar).
// Única excepción: la cuenta del administrador inicial (admin@admin.com).
class EmailInstitucional implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));

        if ($email === 'admin@admin.com') {
            return;
        }

        if (!str_ends_with($email, '@abc.gob.ar')) {
            $fail('El correo tiene que ser @abc.gob.ar.');
        }
    }
}
