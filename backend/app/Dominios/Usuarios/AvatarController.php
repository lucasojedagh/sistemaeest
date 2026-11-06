<?php

namespace App\Dominios\Usuarios;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

// Foto de perfil de las cuentas.
//
// La imagen vive en el disco privado "local" (storage/app/avatars/{id}/), nunca
// en una carpeta web: se sirve por la API y solo a cuentas autenticadas, así no
// hace falta el link público de Storage ni queda expuesta en el servidor.
// El numero del archivo (milisegundos de la subida) es la version: la pantalla
// la usa como clave de caché, entonces una foto nueva se ve al instante y el
// navegador puede conservar la anterior sin que se mezcle.
class AvatarController extends Controller
{
    /** Lado del cuadrado final en px (se recorta al centro). */
    private const LADO = 256;

    /** Tope de la subida, en KB. */
    private const MAX_KB = 4096;

    /** Cuantos ids atiende como máximo una petición de lote. */
    private const LOTE_MAX = 200;

    /** Extensiones posibles del archivo guardado -> mime con el que se devuelve. */
    private const MIMES = [
        'webp' => 'image/webp',
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
    ];

    /**
     * Sube o reemplaza la foto propia.
     * POST /api/profile/avatar  (multipart, campo "avatar")
     */
    public function store(Request $r): JsonResponse
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Sesión no válida.'], 401);
        }

        $r->validate(
            [
                'avatar' => [
                    'required',
                    'file',
                    'mimes:jpg,jpeg,png,webp',
                    'max:' . self::MAX_KB,
                    // Un celular manda 4000x3000 y eso se traduce a ~50 MB de RAM
                    // al decodificar; 5000 px es el corte alto y 100 px el bajo,
                    // por debajo de eso la foto se ve borrosa en cualquier pantalla.
                    'dimensions:min_width=100,min_height=100,max_width=5000,max_height=5000',
                ],
            ],
            [
                'avatar.dimensions' => 'La foto debe ser más grande (mínimo 100x100 px) y no pasar de 5000x5000 px.',
                'avatar.max'        => 'La foto no puede pesar más de 4 MB.',
                'avatar.mimes'      => 'La foto debe ser JPG, PNG o WebP.',
            ],
            [],
            ['avatar' => 'foto']
        );

        $subida = $r->file('avatar');
        $guardado = $this->convertir($subida->getRealPath());

        if ($guardado === null) {
            return response()->json([
                'message' => 'No pudimos procesar esa imagen. Probá con otra foto o enviá el archivo original sin editar.',
            ], 422);
        }

        $nueva = sprintf('avatars/%d/%d.%s', (int) $u->id, $this->version(), $guardado['ext']);
        Storage::disk('local')->put($nueva, $guardado['bytes']);

        $anterior = $u->avatar;
        $u->avatar = $nueva;
        $u->save();

        // La foto vieja ya no sirve: se limpia el directorio completo salvo la nueva.
        $this->limpiar((int) $u->id, $nueva);

        ActivityLogger::log(
            $r,
            ($anterior ? 'Cambió' : 'Subió') . ' su foto de perfil',
            'user.avatar',
            'User',
            (int) $u->id,
            $anterior ? ['foto' => 'anterior'] : null,
            ['foto' => 'nueva']
        );

        $this->olvidarSesionActual($r);

        return response()->json(['avatar_version' => $u->avatar_version], 201);
    }

    /**
     * Quita la foto propia.
     * DELETE /api/profile/avatar
     */
    public function destroy(Request $r): JsonResponse
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Sesión no válida.'], 401);
        }

        $anterior = $u->avatar;
        if (!$anterior) {
            return response()->json(['avatar_version' => null]);
        }

        $u->avatar = null;
        $u->save();
        $this->limpiar((int) $u->id, null);

        ActivityLogger::log($r, 'Quitó su foto de perfil', 'user.avatar', 'User', (int) $u->id, ['foto' => 'anterior'], ['foto' => null]);

        $this->olvidarSesionActual($r);

        return response()->json(['avatar_version' => null]);
    }

    /**
     * El middleware deja el usuario en caché 5 minutos. Se descarta la copia de
     * esta sesión para que un /me inmediato (o un F5) ya vea la foto nueva; las
     * demás sesiones la toman igual por la lista de usuarios, que se lee en la BD.
     */
    private function olvidarSesionActual(Request $r): void
    {
        $token = (string) $r->bearerToken();
        if ($token !== '') {
            Cache::forget('auth_token_' . $token);
        }
    }

    /**
     * Devuelve en un solo pedido las fotos de varios usuarios.
     * GET /api/avatars?ids=2@1730...,13@1730...  -> { "2": { "v": 1730..., "src": "data:image/webp;base64,..." } }
     *
     * Cada id llega con la version que tiene la pantalla ("id@version"), asi la
     * url cambia sola cuando alguien sube una foto nueva y el navegador puede
     * conservar el resto del lote un dia entero sin mostrar nada viejo.
     *
     * Es un lote y no una peticion por foto porque la API tiene su limitador por
     * usuario: la lista de cuentas con 100 filas habria agotado el cupo en un
     * solo render. Los ids sin foto no se incluyen.
     */
    public function index(Request $r): JsonResponse
    {
        $crudos = $r->query('ids', '');
        $crudos = is_array($crudos) ? $crudos : explode(',', (string) $crudos);

        $ids = [];
        foreach ($crudos as $token) {
            // "12@1730736000123" -> 12; un "12" suelto tambien vale.
            $id = (int) trim(strtok((string) $token, '@'));
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_slice(array_keys($ids), 0, self::LOTE_MAX);

        if (!$ids) {
            return response()->json([]);
        }

        $fotos = User::query()
            ->select('id', 'avatar')
            ->whereIn('id', $ids)
            ->whereNotNull('avatar')
            ->get();

        $fuente = [];
        foreach ($fotos as $u) {
            $ruta = (string) $u->avatar;
            $ext  = strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION));
            if (!isset(self::MIMES[$ext])) {
                continue;
            }

            try {
                if (!Storage::disk('local')->exists($ruta)) {
                    continue;
                }
                $bin = Storage::disk('local')->get($ruta);
            } catch (\Throwable $e) {
                report($e);
                continue;
            }

            $fuente[(string) $u->id] = [
                'v'   => $u->avatar_version,
                'src' => 'data:' . self::MIMES[$ext] . ';base64,' . base64_encode($bin),
            ];
        }

        // Las fotos cambian de url cuando suben una nueva (version en la clave),
        // asi que el navegador puede conservar este lote un dia sin riesgo.
        return response()->json($fuente)
            ->header('Cache-Control', 'private, max-age=86400');
    }

    /**
     * Recorta la subida al cuadrado central, la escala a LADO px y la re-codifica.
     * Devuelve ['ext' => ..., 'bytes' => ...] o null si el archivo no es una imagen
     * que este PHP pueda leer.
     */
    private function convertir(string $caminoRuta): ?array
    {
        $bin = @file_get_contents($caminoRuta);
        if ($bin === false || $bin === '') {
            return null;
        }

        // Sin GD (o sin el códec con el que se compiló) no hay forma de recortar.
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $img = @imagecreatefromstring($bin);
        if (!$img) {
            return null;
        }

        $img = $this->enderezar($img, $caminoRuta);

        $ancho = imagesx($img);
        $alto  = imagesy($img);
        $lado  = min($ancho, $alto);
        $x = intdiv($ancho - $lado, 2);
        $y = intdiv($alto - $lado, 2);

        $cuadro = imagecreatetruecolor(self::LADO, self::LADO);
        // Fondo blanco: una PNG con transparencia no puede quedar negra en el circulo.
        imagefill($cuadro, 0, 0, imagecolorallocate($cuadro, 255, 255, 255));
        imagecopyresampled($cuadro, $img, 0, 0, $x, $y, self::LADO, self::LADO, $lado, $lado);
        imagedestroy($img);

        // Se elige el codificador que exista en este PHP: en el contenedor del
        // colegio GD puede venir sin WebP, y en otro sin JPEG.
        $ok = false;
        $ext = 'png';
        ob_start();
        if (function_exists('imagewebp')) {
            $ok = imagewebp($cuadro, null, 82);
            $ext = 'webp';
        } elseif (function_exists('imagejpeg')) {
            $ok = imagejpeg($cuadro, null, 85);
            $ext = 'jpg';
        } elseif (function_exists('imagepng')) {
            $ok = imagepng($cuadro, null, 6);
            $ext = 'png';
        }
        $nuevo = (string) ob_get_clean();
        imagedestroy($cuadro);

        if (!$ok || $nuevo === '') {
            return null;
        }

        return ['ext' => $ext, 'bytes' => $nuevo];
    }

    /**
     * Aplica la rotación que dejó la cámara (EXIF), si el archivo la trae.
     * Sin esto, una foto tomada con el celular vertical sale acostada.
     */
    private function enderezar($img, string $caminoRuta)
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($caminoRuta);
        $orientacion = (int) ($exif['Orientation'] ?? 0);

        if ($orientacion < 3) {
            return $img;
        }

        $rotar = function ($grados) use ($img) {
            // En PHP 8 el color de relleno 0 deja fondo negro; se rellena de blanco.
            $fondo = imagecolorallocate($img, 255, 255, 255);
            $nueva = imagerotate($img, $grados, $fondo);
            return $nueva ?: $img;
        };

        return match ($orientacion) {
            3  => $rotar(180),
            6  => $rotar(-90),
            8  => $rotar(90),
            2  => $this->voltear($img, IMG_FLIP_HORIZONTAL),
            4  => $this->voltear($img, IMG_FLIP_VERTICAL),
            // 5 y 7 son las versiones espejadas de 8 y 6 (selfie con espejo
            // girada): se aplica solo la rotación. La foto queda derecha y, a lo
            // sumo, sin el espejo que traía.
            5  => $rotar(90),
            7  => $rotar(-90),
            default => $img,
        };
    }

    /** Volteo horizontal o vertical, según la orientación EXIF. */
    private function voltear($img, int $modo)
    {
        if (!function_exists('imageflip')) {
            return $img;
        }
        imageflip($img, $modo);
        return $img;
    }

    /** Borra las fotos previas de la cuenta, conservando la ruta indicada. */
    private function limpiar(int $id, ?string $conservar): void
    {
        $carpeta = 'avatars/' . $id;
        try {
            $archivos = Storage::disk('local')->files($carpeta);
            foreach ($archivos as $ruta) {
                if ($conservar !== null && $ruta === $conservar) {
                    continue;
                }
                Storage::disk('local')->delete($ruta);
            }
            // Sin archivos restantes se quita el directorio.
            if (!Storage::disk('local')->files($carpeta)) {
                Storage::disk('local')->deleteDirectory($carpeta);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Version de la foto: milisegundos, para que dos subidas seguidas no choquen. */
    private function version(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
