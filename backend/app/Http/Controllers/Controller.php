<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

// Controlador base del que heredan todos los controladores de la aplicacion.
// Incluye los traits de autorizacion y validacion de Laravel.
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
