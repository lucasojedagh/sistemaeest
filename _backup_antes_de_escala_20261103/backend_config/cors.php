<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paths a los que aplica CORS
    |--------------------------------------------------------------------------
    | Toda la API (el frontend la consume cross-origin en desarrollo).
    */
    'paths' => [
        'api/*',
    ],

    // Métodos permitidos
    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Orígenes permitidos
    |--------------------------------------------------------------------------
    | Cuando supports_credentials=true NO puede ser '*'.
    | Ajustá FRONTEND_URL en .env si tu host/puerto es otro.
    */
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Headers permitidos y expuestos
    |--------------------------------------------------------------------------
    | Authorization y Set-Cookie/XSRF-TOKEN se exponen por si los necesitás
    | en el front (p. ej., para leer tokens o depurar).
    */
    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'Set-Cookie',
        'XSRF-TOKEN',
    ],

    // Cache de la respuesta preflight (opcional subirlo)
    'max_age' => 0,

    /*
    |--------------------------------------------------------------------------
    | Credenciales
    |--------------------------------------------------------------------------
    | Imprescindible en TRUE si el front envía credentials:'include'
    | o trabajás con cookies de sesión.
    */
    'supports_credentials' => true,
];
