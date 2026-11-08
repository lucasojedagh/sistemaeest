<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use Illuminate\Testing\TestResponse;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Qué pasa cuando un archivo llega más grande de lo que el server acepta.
 *
 * Hay tres topes distintos y cada uno corta en un lugar:
 *   1) El `max:` de la app (50 MB documentos y respaldo, 20 MB planilla).
 *   2) upload_max_filesize de PHP: el archivo llega marcado con error, el resto
 *      del pedido está intacto y la validación responde con la regla `uploaded`.
 *   3) post_max_size de PHP: se descarta TODO el body, Laravel avisa 413 con el
 *      message vacío y la pantalla decía "revisá tu conexión", que es mentira.
 *
 * El caso 2 y 3 dependen de ini_set() que no se puede tocar en tiempo de
 * ejecución, así que se simulan los estados que PHP produce (el código de error
 * en el archivo y el CONTENT_LENGTH del pedido) y se mide la respuesta real.
 */
class SubidasGrandesApiTest extends ApiTestCase
{
    /** Un archivo que PHP rechazó por tamaño: existe la marca, no los bytes. */
    private function subidaRechazada(string $nombre = 'padron.pdf'): UploadedFile
    {
        return new UploadedFile('', $nombre, 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
    }

    /** @param array<string, mixed> $extras */
    private function subirDocumento(string $nombre, UploadedFile $archivo, array $extras = []): TestResponse
    {
        return $this->como('admin')->postJson('/api/documentos', array_merge([
            'nombre' => $nombre,
            'archivo' => $archivo,
        ], $extras));
    }

    // ===== 2) PHP rechaza el archivo por upload_max_filesize =====

    public function test_un_archivo_que_php_rechaza_se_avisa_en_espanol(): void
    {
        $antes = DB::table('documentos')->count();

        $respuesta = $this->subirDocumento('Padrón 2026', $this->subidaRechazada());

        // Antes respondía el crudo "validation.uploaded": la clave del idioma no
        // estaba escrita y en pantalla aparecía el nombre de la regla.
        $respuesta->assertStatus(422);
        $mensaje = $respuesta->json('errors.archivo.0');
        $this->assertIsString($mensaje);
        $this->assertStringNotContainsString('validation.', (string) $mensaje);
        $this->assertStringContainsString('no se pudo subir', (string) $mensaje);
        $this->assertSame($antes, DB::table('documentos')->count(), 'no tiene que quedar ningún documento cargado');
    }

    public function test_la_clave_de_archivo_no_subido_existe_en_el_idioma_del_sistema(): void
    {
        $texto = __('validation.uploaded');

        $this->assertNotSame('validation.uploaded', $texto);
        $this->assertStringContainsString('servidor', $texto);
    }

    // ===== 1) el tope de la app =====

    public function test_el_tope_de_un_documento_se_dice_en_megabytes(): void
    {
        $respuesta = $this->subirDocumento(
            'Escaneo grande',
            UploadedFile::fake()->create('escaneo.pdf', 51201)
        );

        $respuesta->assertStatus(422)
            ->assertJsonValidationErrors('archivo');
        $this->assertStringContainsString('50 MB', (string) $respuesta->json('errors.archivo.0'));
        $this->assertSame(0, DB::table('documentos')->count());
    }

    public function test_el_tope_de_la_planilla_de_alumnos_se_dice_en_megabytes(): void
    {
        $antes = DB::table('alumnos')->count();

        $respuesta = $this->como('admin')->postJson('/api/alumnos/importar/preview', [
            'archivo' => UploadedFile::fake()->create('padron.xlsx', 20481),
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->assertStringContainsString('20 MB', (string) $respuesta->json('errors.archivo.0'));
        $this->assertSame($antes, DB::table('alumnos')->count(), 'la planilla rechazada no da de alta nadie');
    }

    public function test_el_tope_de_un_respaldo_se_dice_en_megabytes_sin_tocar_la_base(): void
    {
        $this->confirmarClave();
        $antes = DB::table('alumnos')->count();

        $respuesta = $this->como('admin')->postJson('/api/backups/sql/import', [
            'file' => UploadedFile::fake()->create('respaldo.txt', 51201),
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('50 MB', (string) $respuesta->json('errors.file.0'));
        $this->assertSame($antes, DB::table('alumnos')->count(), 'la base no se tocó');
    }

    private function confirmarClave(): void
    {
        $this->como('admin')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
    }

    // ===== 3) post_max_size: el body completo descartado =====

    public function test_un_body_mas_grande_que_post_max_size_devuelve_un_413_con_sentido(): void
    {
        $respuesta = $this->render(new PostTooLargeException());

        $this->assertSame(413, $respuesta->getStatusCode());
        $mensaje = (string) json_decode($respuesta->getContent(), true)['message'];

        // Laravel trae el message vacío: sin la traducción propia, la pantalla
        // caía en "revisá tu conexión".
        $this->assertNotSame('', $mensaje);
        $this->assertStringContainsString('demasiado grande para este servidor', $mensaje);
        $this->assertStringContainsString('administrador', $mensaje);
        $this->assertMatchesRegularExpression('/\d+(,\d+)? (MB|GB)/', $mensaje);
    }

    public function test_el_413_tambien_traduce_una_subida_de_alumnos(): void
    {
        $respuesta = $this->render(new PostTooLargeException(), '/api/alumnos/importar/preview');

        $this->assertSame(413, $respuesta->getStatusCode());
        $this->assertStringContainsString(
            'Dividilo en partes más chicas',
            (string) json_decode($respuesta->getContent(), true)['message']
        );
    }

    /**
     * El pedido que supera post_max_size ni llega a la ruta: ValidatePostSize lo
     * corta en el middleware global y lo dibuja el manejador de excepciones, así
     * que se lo pide directamente al manejador, como haría el kernel.
     */
    private function render(PostTooLargeException $e, string $ruta = '/api/documentos')
    {
        /** @var ExceptionHandler $manejador */
        $manejador = $this->app->make(ExceptionHandler::class);

        return $manejador->render(Request::create($ruta, 'POST'), $e);
    }

    // ===== el límite escrito como lo lee una persona =====

    public function test_los_limites_del_servidor_se_leen_como_persona(): void
    {
        $leer = new ReflectionMethod(Handler::class, 'limiteLegible');
        $leer->setAccessible(true);

        foreach ([
            '64M' => '64 MB',
            '2G'  => '2 GB',
            '512K' => '0.5 MB',
            '1048576' => '1 MB',
            // Un número suelto en el ini son bytes: 2048 bytes son 2 KB, no 2 MB.
            '2048' => '2 KB',
        ] as $valorIni => $esperado) {
            $this->assertSame($esperado, $leer->invoke(null, $valorIni), "ini_get('$valorIni')");
        }

        // 0 y vacío: no hay límite declarado, no se inventa uno.
        $this->assertSame('el máximo configurado', $leer->invoke(null, '0'));
        $this->assertSame('el máximo configurado', $leer->invoke(null, ''));
    }
}
