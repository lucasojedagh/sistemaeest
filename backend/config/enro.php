<?php

// Ajustes propios del sistema. Se leen con config() desde el código: env() en
// un controlador devuelve null cuando la configuración está cacheada
// (php artisan config:cache), y el ajuste quedaría ignorado sin avisar.
//
// En el .env del server:
//   DB_BACKUP_MYSQLDUMP=/usr/bin   (carpeta o archivo del mysqldump; vacío = PATH)
//   SLOW_REQUEST_MS=2000           (umbral para registrar un pedido como lento)

return [

    'backup_mysqldump' => env('DB_BACKUP_MYSQLDUMP', ''),

    'slow_request_ms' => (int) (env('SLOW_REQUEST_MS', 2000) ?: 2000),

];