<?php

namespace Tests\Feature;

use App\Dominios\Sistema\BackupController;
use App\Dominios\Usuarios\RolConfig;
use App\Dominios\Usuarios\User;
use App\Support\Permisos;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Los respaldos, recorridos por HTTP con login real.
 *
 * Es el módulo más delicado del sistema: el download baja la base completa y el
 * import la reemplaza. Por eso acá se mide el candado (quién entra, con qué
 * clave confirmada), qué trae exactamente el .sql que se descarga y cómo corta
 * las sentencias el restaurador. El import destructivo no se corre contra una
 * base con datos: su COMMIT confirmaría la transacción de la propia prueba.
 */
class RespaldosApiTest extends ApiTestCase
{
    private function confirmar(string $rol = 'admin'): void
    {
        $this->como($rol)->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
    }

    /** Rol personalizado con fila en roles_config: el catálogo manda sobre el rango. */
    private function cuentaConRol(string $email, string $rol, array $permitidos): User
    {
        RolConfig::firstOrCreate(
            ['rol' => $rol],
            ['nombre' => ucfirst($rol), 'nivel' => 5, 'tipo' => 'personalizado',
             'permitidos' => Permisos::normalizarLista($rol, $permitidos, 5),
             'negados' => [], 'alcance' => [], 'modulos' => null]
        );
        RolConfig::olvidarRegistro();

        return User::create(['name' => 'Prueba', 'apellido' => 'Rol', 'email' => $email,
                             'password' => self::CLAVE, 'role' => $rol, 'status' => 'active']);
    }

    // ===== GET /backups/sql/check =====

    public function test_el_diagnostico_de_respaldos_lo_lee_solo_el_admin(): void
    {
        $this->como('admin')->getJson('/api/backups/sql/check')->assertOk();

        foreach (['directivo', 'jefe_preceptores', 'prosecretaria', 'preceptor', 'profesor', 'sin_rol'] as $rol) {
            $this->como($rol)->getJson('/api/backups/sql/check')->assertForbidden();
        }
    }

    public function test_sin_sesion_el_diagnostico_no_se_lee(): void
    {
        $this->sinSesion()->getJson('/api/backups/sql/check')->assertUnauthorized();
    }

    public function test_un_rol_personalizado_de_rango_cinco_sin_la_casilla_no_ve_el_diagnostico(): void
    {
        // El rango 5 imita al Admin, pero la casilla backup.usar está en
        // SOLO_ADMIN: sin esa fila del catálogo la ruta la frena 'permiso'.
        $cuenta = $this->cuentaConRol('rango5@abc.gob.ar', 'rango_cinco', []);
        $this->withToken($this->tokenDeEmail($cuenta->email))
            ->getJson('/api/backups/sql/check')->assertForbidden();
    }

    public function test_el_diagnostico_muestra_la_base_a_la_que_esta_conectado(): void
    {
        $r = $this->como('admin')->getJson('/api/backups/sql/check')->assertOk();

        $this->assertTrue($r->json('db.configured'));
        $this->assertSame(config('database.connections.mysql.database'), $r->json('db.database'));
        $this->assertSame(config('database.connections.mysql.host'), $r->json('db.host'));
        $this->assertStringContainsString('backups', $r->json('storage_backups'));
        $this->assertArrayHasKey('exists', $r->json('mysqldump'));
    }

    public function test_el_diagnostico_no_publica_las_credenciales_de_la_base(): void
    {
        $r = $this->como('admin')->getJson('/api/backups/sql/check')->assertOk();

        $this->assertSame(['host', 'port', 'database', 'configured'], array_keys($r->json('db')));
        $this->assertStringNotContainsString(self::CLAVE, $r->getContent());

        $claveDeBase = (string) (config('database.connections.mysql.password') ?? '');
        if ($claveDeBase !== '') {
            $this->assertStringNotContainsString($claveDeBase, $r->getContent(), 'El diagnóstico filtra la clave de MySQL');
        }
    }

    // ===== GET /backups/sql/download =====

    public function test_descargar_el_respaldo_pide_confirmar_la_clave_primero(): void
    {
        $r = $this->como('admin')->getJson('/api/backups/sql/download');

        $r->assertStatus(422);
        $this->assertStringContainsString('Confirmá tu contraseña', (string) $r->json('message'));
    }

    public function test_el_respaldo_baja_como_archivo_sql_con_la_estructura_y_los_datos(): void
    {
        $this->confirmar();

        /** @var TestResponse $r */
        $r = $this->como('admin')->getJson('/api/backups/sql/download')->assertOk();

        $disposition = $r->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', (string) $disposition);
        $this->assertMatchesRegularExpression('/filename="[^"]*_db_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql"/', (string) $disposition);
        $this->assertStringContainsString('sql', (string) $r->headers->get('Content-Type'));

        $sql = $r->getContent();
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `alumnos`', $sql);
        $this->assertStringContainsString('CREATE TABLE `alumnos`', $sql);
        $this->assertStringContainsString('INSERT INTO `alumnos`', $sql);
        $this->assertStringContainsString('40000001', $sql, 'El respaldo lleva los alumnos del fixture');
        $this->assertStringEndsWith("SET FOREIGN_KEY_CHECKS=1;\n", $sql, 'Cierra reactivando las claves foráneas');
    }

    public function test_el_respaldo_no_lleva_la_clave_en_texto_plano(): void
    {
        $this->confirmar();

        $sql = $this->como('admin')->getJson('/api/backups/sql/download')->assertOk()->getContent();

        $this->assertStringNotContainsString(self::CLAVE, $sql, 'La clave de las cuentas no puede salir del dump');
        $this->assertStringContainsString('$2y$', $sql, 'Las cuentas viajan con su hash, como en la base');
    }

    public function test_un_no_admin_no_descarga_el_respaldo_aunque_confirme_su_clave(): void
    {
        $this->confirmar('preceptor');

        $this->como('preceptor')->getJson('/api/backups/sql/download')->assertForbidden();
    }

    public function test_la_descarga_del_respaldo_deja_huella_en_el_historial(): void
    {
        $this->confirmar();
        $this->como('admin')->getJson('/api/backups/sql/download')->assertOk();

        $this->assertTrue(
            DB::table('activity_logs')->where('action_type', 'backup.download_sql')->exists(),
            'Bajar la base entera tiene que quedar anotado'
        );
    }

    // ===== POST /backups/sql/import: los candados, sin tocar los datos =====

    public function test_restaurar_sin_confirmar_la_clave_no_escribe_nada(): void
    {
        $antes = DB::table('alumnos')->count();

        $r = $this->como('admin')->postJson('/api/backups/sql/import', [
            'file' => UploadedFile::fake()->createWithContent('respaldo.sql', "INSERT INTO `alumnos` (dni) VALUES ('99999999');\n"),
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('Confirmá tu contraseña', (string) $r->json('message'));
        $this->assertSame($antes, DB::table('alumnos')->count());
    }

    public function test_un_rol_que_no_es_admin_no_puede_restaurar(): void
    {
        $this->confirmar('directivo');

        $this->como('directivo')->postJson('/api/backups/sql/import', [
            'file' => UploadedFile::fake()->createWithContent('respaldo.sql', "SELECT 1;\n"),
        ])->assertForbidden();
    }

    public function test_un_archivo_que_no_es_sql_se_rechaza_antes_de_ejecutar_nada(): void
    {
        $this->confirmar();

        $r = $this->como('admin')->postJson('/api/backups/sql/import', [
            'file' => UploadedFile::fake()->createWithContent('notas.pdf', "%PDF-1.4 no soy un respaldo\n"),
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_un_archivo_sin_sentencias_se_rechaza_con_un_mensaje_claro(): void
    {
        $this->confirmar();

        $r = $this->como('admin')->postJson('/api/backups/sql/import', [
            'file' => UploadedFile::fake()->createWithContent('vacio.sql', "  \n\t\n"),
        ]);

        $r->assertStatus(400);
        $this->assertStringContainsString('vacío', (string) $r->json('message'));
    }

    public function test_sin_archivo_el_pedido_es_422(): void
    {
        $this->confirmar();

        $this->como('admin')->postJson('/api/backups/sql/import', [])->assertStatus(422);
    }

    // ===== El cortador de sentencias (el corazón del restaurador) =====

    private function cortar(string $sql): array
    {
        $metodo = new ReflectionMethod(BackupController::class, 'splitSqlStatements');
        $metodo->setAccessible(true);

        return $metodo->invoke(new BackupController(), $sql);
    }

    public function test_un_punto_y_coma_dentro_de_un_texto_no_corta_la_sentencia(): void
    {
        // Es el error que un explode(';') comete: una observación "hola; chau"
        // partiría el INSERT por la mitad y el restore quedaría parcial.
        $sentencias = $this->cortar("INSERT INTO `alumnos` (observaciones) VALUES ('hola; chau');\n");

        $this->assertCount(1, $sentencias);
        $this->assertStringContainsString('hola; chau', $sentencias[0]);
    }

    public function test_unas_comillas_dentro_de_un_texto_no_confunden_al_cortador(): void
    {
        $sentencias = $this->cortar("INSERT INTO t (a) VALUES ('O''Brien; \"x\"');\nINSERT INTO t (a) VALUES (2);\n");

        $this->assertCount(2, $sentencias);
        $this->assertStringContainsString("O''Brien", $sentencias[0]);
        $this->assertStringNotContainsString('VALUES (2)', $sentencias[0], 'La segunda sentencia no se fusiona con la primera');
    }

    public function test_los_comentarios_no_generan_sentencias_fantasma(): void
    {
        $sql = "-- Sistema ENRO - Backup; generado\n# comentario; también\nSET NAMES utf8mb4;\n/* bloque; */\nINSERT INTO t (a) VALUES (1);\n";

        $sentencias = array_values(array_filter(array_map('trim', $this->cortar($sql)), fn ($s) => $s !== ''));

        $this->assertCount(2, $sentencias, 'Los dos comentarios de línea no cuentan; el bloque /* */ queda pegado al INSERT');
        $this->assertStringStartsWith('SET NAMES', $sentencias[0]);
        $this->assertStringStartsWith('/* bloque; */', $sentencias[1]);
        $this->assertStringContainsString('INSERT INTO t', $sentencias[1], 'El ; del comentario no parte el INSERT');
    }

    public function test_una_sentencia_final_sin_punto_y_coma_tambien_se_corta(): void
    {
        $sentencias = $this->cortar("SET FOREIGN_KEY_CHECKS=1;\nUPDATE t SET a = 1");

        $this->assertSame('SET FOREIGN_KEY_CHECKS=1', trim($sentencias[0]));
        $this->assertSame('UPDATE t SET a = 1', trim($sentencias[1]), 'El archivo que no termina en ; no pierde su última sentencia');
    }

    public function test_un_archivo_vacio_no_deja_sentencias(): void
    {
        $this->assertSame([], $this->cortar(''));
    }

    // ===== util =====

    private function tokenDeEmail(string $email): string
    {
        $r = $this->postJson('/api/login', ['email' => $email, 'password' => self::CLAVE])->assertOk();

        return (string) $r->json('token');
    }
}
