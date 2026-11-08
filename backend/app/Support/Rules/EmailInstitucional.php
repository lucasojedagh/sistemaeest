<?php

namespace App\Support\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Support\Texto;

// El correo de una cuenta tiene que ser institucional (@abc.gob.ar).
// Única excepción: la cuenta del administrador inicial (admin@admin.com).
class EmailInstitucional implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // El correo puede llegar como lista desde un pedido armado fuera de la
        // pantalla; leerlo directo como texto cortaba el registro público con
        // un 500 en vez de responder el aviso de que el correo no sirve.
        $email = Texto::minusculo($value);

        if ($email === 'admin@admin.com') {
            return;
        }

        if (!str_ends_with($email, '@abc.gob.ar')) {
            $fail('El correo tiene que ser @abc.gob.ar.');
        }
    }
}
