<?php

namespace Tests\Feature;

use App\Dominios\Usuarios\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * La foto de perfil, recorrida por HTTP con login real.
 *
 * Lo que importa acá es lo que el usuario ve en pantalla: que la foto que sube
 * se vea ya en todas las listas sin recargar, que reemplazarla borre la anterior
 * (no acumular archivos en el server), que un archivo que no es una foto se
 * rechace con un mensaje en español y que nadie pueda leer la foto de otro sin
 * estar dentro del sistema.
 */
class AvataresApiTest extends ApiTestCase
{
    /** Sube una foto real de lado x lado y devuelve la respuesta. */
    private function subir(string $rol, int $ancho = 300, int $alto = 300, string $nombre = 'foto.jpg'): TestResponse
    {
        return $this->como($rol)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image($nombre, $ancho, $alto),
        ]);
    }

    private function foto(string $rol = 'admin'): User
    {
        return $this->cuenta($rol);
    }

    // ===== POST /profile/avatar =====

    public function test_subir_una_foto_la_guarda_recuadrada_y_devuelve_su_version(): void
    {
        $r = $this->subir('admin', 400, 260)->assertCreated();

        $cuenta = $this->foto('admin');
        $version = $r->json('avatar_version');
        $this->assertIsInt($version);
        $this->assertGreaterThan(0, $version);

        $ruta = (string) $cuenta->avatar;
        $this->assertSame("avatars/{$cuenta->id}/{$version}.webp", $ruta, 'La foto vive en la carpeta privada de la cuenta');
        $this->assertTrue(Storage::disk('local')->exists($ruta));

        // El recorte al centro y la escala a 256 px son lo que hace que la misma
        // foto sirva para el círculo de la barra y para la ficha.
        [$ancho, $alto] = getimagesizefromstring(Storage::disk('local')->get($ruta));
        $this->assertSame([256, 256], [$ancho, $alto], 'Toda foto termina en un cuadrado de 256 px');
    }

    public function test_la_version_de_la_foto_sale_sola_en_el_me_despues_de_subirla(): void
    {
        $antes = $this->como('admin')->getJson('/api/me')->assertOk()->json('avatar_version');
        $this->assertNull($antes, 'Sin foto la versión es null');

        $nueva = $this->subir('admin')->assertCreated()->json('avatar_version');

        // El middleware deja la cuenta cacheada 5 minutos: si no se descarta esa
        // copia, el F5 o el /me siguiente muestra la foto vieja.
        $despues = $this->como('admin')->getJson('/api/me')->assertOk()->json('avatar_version');
        $this->assertSame($nueva, $despues);
    }

    public function test_reemplazar_la_foto_borra_la_anterior(): void
    {
        $primera = $this->subir('admin')->assertCreated()->json('avatar_version');
        $this->subir('admin')->assertCreated();

        $carpeta = 'avatars/' . $this->foto('admin')->id;
        $archivos = Storage::disk('local')->files($carpeta);

        $this->assertCount(1, $archivos, 'La foto vieja no se queda ocupando espacio en el server');
        $this->assertStringNotContainsString((string) $primera, $archivos[0]);
        $this->assertStringContainsString((string) $this->foto('admin')->avatar_version, $archivos[0]);
    }

    public function test_dos_subidas_seguidas_no_chocan_entre_si(): void
    {
        $v1 = $this->subir('admin')->assertCreated()->json('avatar_version');
        $v2 = $this->subir('admin')->assertCreated()->json('avatar_version');

        $this->assertNotSame($v1, $v2, 'La versión es el milisegundo de la subida: dos fotos no pueden compartir número');
    }

    public function test_cada_cuenta_subiu_su_propia_foto(): void
    {
        $adminAntes = $this->foto('admin')->avatar;

        $this->subir('preceptor')->assertCreated();

        $this->assertSame($adminAntes, $this->foto('admin')->avatar, 'Subir la foto propia no toca la de otro');
        $this->assertNotNull($this->cuenta('preceptor')->avatar);
    }

    public function test_un_archivo_que_no_es_una_foto_se_rechaza_en_espanol(): void
    {
        $r = $this->como('admin')->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('apuntes.pdf', "%PDF-1.4 no soy una foto\n"),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');

        $this->assertSame('La foto debe ser JPG, PNG o WebP.', $r->json('errors.avatar.0'));
        $this->assertNull($this->foto('admin')->avatar);
    }

    public function test_una_foto_diminuta_se_rechaza_con_el_minimo_escrito(): void
    {
        $r = $this->subir('admin', 40, 40)->assertStatus(422)->assertJsonValidationErrors('avatar');

        $this->assertSame('La foto debe ser más grande (mínimo 100x100 px) y no pasar de 5000x5000 px.', $r->json('errors.avatar.0'));
    }

    public function test_una_foto_de_mas_de_cuatro_mega_se_rechaza(): void
    {
        $r = $this->como('admin')->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('gigante.jpg', 5000),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');

        $this->assertSame('La foto no puede pesar más de 4 MB.', $r->json('errors.avatar.0'));
    }

    public function test_sin_foto_en_el_pedido_el_sistema_avisa_en_lugar_de_romperse(): void
    {
        $this->como('admin')->postJson('/api/profile/avatar', [])->assertStatus(422);
        $this->como('admin')->postJson('/api/profile/avatar', ['avatar' => 'una cadena'])->assertStatus(422);
    }

    public function test_subir_o_quitar_la_foto_deja_huella_en_el_historial(): void
    {
        // La fila del login es siempre la más nueva: se limpia después de entrar.
        $this->tokenDe('admin');
        DB::table('activity_logs')->delete();

        $this->subir('admin')->assertCreated();
        $this->assertSame('user.avatar', DB::table('activity_logs')->latest('id')->value('action_type'));

        $this->como('admin')->deleteJson('/api/profile/avatar')->assertOk();
        $this->assertSame('user.avatar', DB::table('activity_logs')->latest('id')->value('action_type'));
    }

    public function test_sin_sesion_no_se_puede_subir_ni_quitar_la_foto(): void
    {
        $this->sinSesion()->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ])->assertUnauthorized();

        $this->sinSesion()->deleteJson('/api/profile/avatar')->assertUnauthorized();
    }

    // ===== DELETE /profile/avatar =====

    public function test_quitar_la_foto_deja_la_carpeta_de_la_cuenta_vacia(): void
    {
        $this->subir('admin')->assertCreated();

        $r = $this->como('admin')->deleteJson('/api/profile/avatar')->assertOk();

        $this->assertNull($r->json('avatar_version'));
        $this->assertNull($this->foto('admin')->avatar);
        $this->assertSame([], Storage::disk('local')->files('avatars/' . $this->foto('admin')->id));
    }

    public function test_quitar_una_foto_que_no_existe_no_es_un_error(): void
    {
        $this->como('admin')->deleteJson('/api/profile/avatar')
            ->assertOk()->assertJson(['avatar_version' => null]);
    }

    public function test_despues_de_quitar_la_foto_el_me_la_muestra_como_ausente(): void
    {
        $this->subir('admin')->assertCreated();
        $this->como('admin')->deleteJson('/api/profile/avatar')->assertOk();

        $this->assertNull($this->como('admin')->getJson('/api/me')->assertOk()->json('avatar_version'));
    }

    // ===== GET /avatars =====

    public function test_la_lista_de_fotos_trae_el_image_en_base64_con_su_version(): void
    {
        $version = $this->subir('admin')->assertCreated()->json('avatar_version');
        $admin = $this->foto('admin');

        $r = $this->como('profesor')->getJson('/api/avatars?ids=' . $admin->id . '@' . $version)->assertOk();

        $this->assertSame($version, $r->json($admin->id . '.v'));
        $src = (string) $r->json($admin->id . '.src');
        $this->assertStringStartsWith('data:image/webp;base64,', $src);
        $this->assertSame(
            Storage::disk('local')->get($admin->avatar),
            base64_decode(substr($src, strpos($src, ',') + 1)),
            'La foto que devuelve el lote es la que está guardada'
        );
        $cacheControl = (string) $r->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl, 'El lote de fotos se puede conservar un día');
    }

    public function test_la_lista_de_fotos_omite_las_cuentas_sin_foto(): void
    {
        $conFoto = $this->foto('admin');
        $this->subir('admin')->assertCreated();
        $sinFoto = $this->cuenta('preceptor');

        $r = $this->como('profesor')->getJson('/api/avatars?ids=' . $conFoto->id . ',' . $sinFoto->id)->assertOk();

        $this->assertSame([$conFoto->id], array_keys($r->json()));
        $this->assertArrayNotHasKey((string) $sinFoto->id, $r->json());
    }

    public function test_la_lista_de_fotos_no_filtra_la_ruta_de_storage(): void
    {
        $this->subir('admin')->assertCreated();

        $r = $this->como('profesor')->getJson('/api/avatars?ids=' . $this->foto('admin')->id)->assertOk();

        // La respuesta es { id: { v, src } } y nada más: si algún día se agrega
        // un campo, aquí se ve antes de que salga la ruta interna del archivo.
        foreach ($r->json() as $item) {
            $this->assertSame(['v', 'src'], array_keys($item));
            $this->assertStringStartsWith('data:image/', $item['src']);
        }
        $this->assertStringNotContainsString('avatars/', $r->getContent());
    }

    public function test_la_lista_de_fotos_acepta_ids_raros_sin_romperse(): void
    {
        $id = $this->foto('admin')->id;
        $this->subir('admin')->assertCreated();

        foreach (['', 'ids que no son numeros', '0', '@@@', '-4', $id . '@9999999999999'] as $crudos) {
            $r = $this->como('profesor')->getJson('/api/avatars?ids=' . urlencode($crudos))->assertOk();
            $this->assertIsArray($r->json());
        }

        // Un id con una versión inventada igual se sirve: la versión que pide la
        // pantalla es para la caché del navegador; lo que devuelve el server es la
        // real, para que la foto nueva aparezca aunque la pantalla traiga la vieja.
        $r = $this->como('profesor')->getJson('/api/avatars?ids=' . $id . '@9999999999999')->assertOk();
        $this->assertArrayHasKey((string) $id, $r->json());
        $this->assertSame($this->foto('admin')->avatar_version, $r->json($id . '.v'));
    }

    public function test_la_lista_de_fotos_descarta_los_ids_repetidos(): void
    {
        $this->subir('admin')->assertCreated();
        $id = $this->foto('admin')->id;

        $r = $this->como('profesor')->getJson('/api/avatars?ids=' . $id . ',' . $id . ',' . $id)->assertOk();

        $this->assertCount(1, $r->json());
    }

    public function test_las_fotos_se_leen_sin_permisos_especiales_pero_con_sesion(): void
    {
        // Identificar personas no es un dato sensible: cualquier cuenta autenticada
        // las necesita para las listas. Sin sesión, nada.
        $this->subir('admin')->assertCreated();
        $ids = $this->foto('admin')->id;

        foreach (['profesor', 'preceptor', 'sin_rol', 'directivo'] as $rol) {
            $this->como($rol)->getJson('/api/avatars?ids=' . $ids)->assertOk();
        }

        $this->sinSesion()->getJson('/api/avatars?ids=' . $ids)->assertUnauthorized();
    }

    public function test_un_rol_personalizado_sin_permisos_tambien_ve_las_fotos(): void
    {
        \App\Dominios\Usuarios\RolConfig::create([
            'rol' => 'solo_lectura', 'nombre' => 'Solo Lectura', 'nivel' => 1, 'tipo' => 'personalizado',
            'permitidos' => [], 'negados' => [], 'alcance' => [], 'modulos' => null,
        ]);
        \App\Dominios\Usuarios\RolConfig::olvidarRegistro();

        $cuenta = User::create(['name' => 'Mirón', 'apellido' => 'DePrueba', 'email' => 'miron@abc.gob.ar',
                                'password' => self::CLAVE, 'role' => 'solo_lectura', 'status' => 'active']);

        $this->subir('admin')->assertCreated();
        $token = (string) $this->postJson('/api/login', ['email' => $cuenta->email, 'password' => self::CLAVE])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/avatars?ids=' . $this->foto('admin')->id)
            ->assertOk()->assertJsonStructure([(string) $this->foto('admin')->id => ['v', 'src']]);
    }

    public function test_una_cuenta_pendiente_no_llega_a_ver_las_fotos(): void
    {
        // La foto la pide una pantalla ya adentro del sistema: una cuenta que
        // todavía no está verificada no obtiene token, así que tampoco las ve.
        $this->postJson('/api/login', [
            'email' => $this->cuentas['pendiente']->email, 'password' => self::CLAVE,
        ])->assertStatus(403)->assertJsonPath('message', 'Tu cuenta está pendiente de verificación. Esperá a que un administrador la active.');
    }
}
