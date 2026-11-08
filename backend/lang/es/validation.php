<?php

// Mensajes de validación en español rioplatense simple.
// Se usan cuando una regla no define su propio mensaje.
return [
    'accepted' => 'Tenés que aceptar este campo.',
    'active_url' => 'Esta dirección web no es válida.',
    'after' => 'Tiene que ser una fecha posterior a :date.',
    'alpha' => 'Solo puede contener letras.',
    'alpha_num' => 'Solo puede contener letras y números.',
    'array' => 'Este campo no llegó con el formato esperado.',
    // Sin estas claves la pantalla mostraba el crudo "validation.between" o
    // "validation.present"; son las reglas que usa este sistema.
    'between' => [
        'string' => 'Tiene que tener entre :min y :max letras.',
        'numeric' => 'Tiene que estar entre :min y :max.',
        'file' => 'El archivo tiene que pesar entre :min y :max kilobytes.',
        'array' => 'Tiene que tener entre :min y :max elementos.',
    ],
    'before' => 'Tiene que ser una fecha anterior a :date.',
    'boolean' => 'Este campo tiene que ser sí o no.',
    // La reconfirmación antes de acciones delicadas (cambiar clave, limpiar
    // el historial): si la clave no es la de esta cuenta, eso es lo que hay
    // que leer, no "validation.current_password".
    'current_password' => 'La contraseña no es la de esta cuenta.',
    'confirmed' => 'La confirmación no coincide.',
    'date' => 'No es una fecha válida.',
    'date_format' => 'La fecha no tiene el formato :format.',
    // La foto de perfil se controla con dimensions; sin esto el usuario leía
    // "validation.dimensions" después de subir una imagen demasiado chica.
    'dimensions' => 'La imagen no sirve: es demasiado chica o demasiado grande.',
    'different' => 'Este campo y :other tienen que ser distintos.',
    'digits' => 'Tiene que tener :digits dígitos.',
    'digits_between' => 'Tiene que tener entre :min y :max dígitos.',
    'email' => 'No parece un correo válido. Revisalo.',
    'exists' => 'El valor elegido no es válido.',
    'file' => 'Tiene que ser un archivo.',
    'filled' => 'Este campo no puede estar vacío.',
    'image' => 'Tiene que ser una imagen.',
    'in' => 'Elegí una opción de la lista.',
    'integer' => 'Tiene que ser un número entero.',
    'max' => [
        'string' => 'No puede tener más de :max letras.',
        'numeric' => 'No puede ser mayor que :max.',
        'file' => 'El archivo es más grande de lo permitido (:max KB). Elegí uno más chico.',
        'array' => 'No puede tener más de :max elementos.',
    ],
    // PHP rechazó la subida antes de que la veamos: pesa más que
    // upload_max_filesize, se cortó a mitad o no llegó el archivo temporal.
    // Sin esta clave el usuario leía el crudo "validation.uploaded" en pantalla.
    'uploaded' => 'El archivo no se pudo subir. Pesa más de lo que este servidor admite o se cortó a mitad; intentá de nuevo con uno más chico.',
    'mimes' => 'El archivo tiene que ser de tipo: :values.',
    'min' => [
        'string' => 'Tiene que tener al menos :min letras.',
        'numeric' => 'Tiene que ser al menos :min.',
        'file' => 'El archivo tiene que pesar al menos :min kilobytes.',
        'array' => 'Tiene que tener al menos :min elementos.',
    ],
    'not_in' => 'El valor elegido no es válido.',
    // "present": la clave tenía que venir en el pedido, aunque viniera vacía.
    // Pasa cuando la pantalla manda a guardar sin el bloque de asignaciones
    // cargado; sin esta clave se leía "validation.present" en el modal.
    'present' => 'Ese dato no llegó en el pedido. Recargá la pantalla y volvé a intentar.',
    'numeric' => 'Tiene que ser un número.',
    'regex' => 'El formato no es válido. Revisalo.',
    'required' => 'Este campo es obligatorio.',
    'same' => 'Este campo y :other tienen que coincidir.',
    'size' => [
        'string' => 'Tiene que tener :size letras.',
        'numeric' => 'Tiene que ser :size.',
        'file' => 'El archivo tiene que pesar :size kilobytes.',
        'array' => 'Tiene que tener :size elementos.',
    ],
    'string' => 'Este campo tiene que ser texto.',
    'unique' => 'Ese valor ya está registrado.',
    'url' => 'No parece una dirección web válida.',

    // Nombres amables para los campos (se usan en los mensajes con :attribute).
    'attributes' => [
        'name' => 'nombre',
        'apellido' => 'apellido',
        'dni' => 'DNI',
        'email' => 'correo',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'rol_solicitado' => 'rol',
        'token' => 'código del enlace',
        'anio' => 'año',
        'division' => 'división',
        'materia_id' => 'materia',
    ],
];
