<?php

namespace App\Dominios\Sistema;

use App\Http\Controllers\Controller;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;

/**
 * Manuales del sistema publicados dentro de la app.
 *
 * El parametro de la ruta es una clave de la lista fija, nunca un camino: asi no
 * hay forma de pedir un archivo que no este aqui declarado.
 */
class ManualController extends Controller
{
    private const MANUALES = [
        'usuario' => [
            'titulo' => 'Manual de usuario',
            'archivo' => 'Manual-de-usuario-Sistema-EEST.pdf',
            'descripcion' => 'Como se usa el sistema pantalla por pantalla: alumnos, cursos, asistencia, notas y documentos.',
        ],
        'programador' => [
            'titulo' => 'Manual del programador',
            'archivo' => 'Manual-del-programador-Sistema-EEST.pdf',
            'descripcion' => 'Arquitectura, base de datos y codigo: para mantener o continuar el proyecto.',
            'solo_gestion' => true,
        ],
    ];

    /** Lista de manuales disponibles con su fecha y tamano, sin rutas del servidor. */
    public function index(Request $request)
    {
        $out = [];
        foreach (self::MANUALES as $clave => $m) {
            if (!empty($m['solo_gestion']) && !$this->esGestionAlta($request)) {
                continue;
            }
            $ruta = $this->ruta($clave);
            if ($ruta === null) {
                continue;
            }
            $out[] = [
                'clave' => $clave,
                'titulo' => $m['titulo'],
                'descripcion' => $m['descripcion'],
                'archivo' => $m['archivo'],
                'tamano_kb' => (int) round(filesize($ruta) / 1024),
                'actualizado' => date('Y-m-d', filemtime($ruta)),
            ];
        }
        return ['manuales' => $out];
    }

    /** Devuelve el PDF para verlo en el navegador (inline) o descargarlo. */
    public function show(Request $request, string $clave)
    {
        if (!isset(self::MANUALES[$clave])) {
            return response()->json(['message' => 'Ese manual no existe.'], 404);
        }

        if (!empty(self::MANUALES[$clave]['solo_gestion']) && !$this->esGestionAlta($request)) {
            return response()->json(['message' => 'Ese manual es solo para direccion y administracion.'], 403);
        }

        $ruta = $this->ruta($clave);
        if ($ruta === null) {
            return response()->json(['message' => 'El manual todavia no esta cargado en el servidor.'], 404);
        }

        $nombre = self::MANUALES[$clave]['archivo'];
        $descarga = $request->query('descarga') === '1';

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($descarga ? 'attachment' : 'inline')
                . '; filename="' . $nombre . '"',
        ]);
    }

    /**
     * Manual técnico: lo decide el permiso 'manuales.programador', que por
     * defecto trae dirección y administración.
     */
    private function esGestionAlta(Request $request): bool
    {
        return \App\Support\Permisos::puede($request->user()->role, 'manuales.programador');
    }

    /** Camino real del PDF, buscando la carpeta docs en las ubicaciones posibles. */
    private function ruta(string $clave): ?string
    {
        $carpetas = [
            base_path('../docs'),
            base_path('docs'),
            public_path('docs'),
        ];
        foreach ($carpetas as $carpeta) {
            $ruta = $carpeta . '/' . self::MANUALES[$clave]['archivo'];
            if (is_file($ruta) && is_readable($ruta)) {
                return $ruta;
            }
        }
        return null;
    }
}
