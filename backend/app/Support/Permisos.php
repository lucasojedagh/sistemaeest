<?php

namespace App\Support;

use App\Dominios\Usuarios\RolConfig;
use Illuminate\Http\Request;

/**
 * Catálogo de permisos del sistema.
 *
 * Cada clave describe UNA cosa que se puede hacer (ver, crear, editar, eliminar)
 * y tiene un nivel por defecto: el rango mínimo de RoleHierarchy que la trae
 * incluida. Así los roles de sistema siguen funcionando como hasta hoy sin
 * necesidad de una fila en roles_config.
 *
 * Roles de sistema  = permisos del nivel, menos los 'negados' de su fila.
 * Roles personalizados = 'permitidos' menos 'negados' (lista negra explícita).
 */
class Permisos
{
    /** @var array<string, array{label: string, nivel: int, desc: string}> */
    private const CATALOGO = [
        'alumnos' => [
            'grupo' => 'Alumnos',
            'items' => [
                'alumnos.ver'          => ['label' => 'Ver padrón y fichas de alumnos', 'nivel' => 1, 'desc' => 'Lista de alumnos, ficha completa y horario del alumno.'],
                'alumnos.padron'       => ['label' => 'Descargar el padrón en Excel', 'nivel' => 1, 'desc' => 'Botón "Descargar lista" del módulo Alumnos.'],
                'alumnos.crear'        => ['label' => 'Crear alumnos', 'nivel' => 3, 'desc' => 'Altas de alumnos nuevos.'],
                'alumnos.importar'     => ['label' => 'Importar alumnos desde Excel', 'nivel' => 3, 'desc' => 'Plantilla, vista previa y alta masiva del padrón.'],
                'alumnos.editar'       => ['label' => 'Editar alumnos', 'nivel' => 2, 'desc' => 'Modificar datos de un alumno (en el ámbito del rol).'],
                'alumnos.eliminar'     => ['label' => 'Eliminar alumnos', 'nivel' => 3, 'desc' => 'Baja de alumnos.'],
                'alumnos.egresar'      => ['label' => 'Egresar alumnos', 'nivel' => 3, 'desc' => 'Marcar alumno como egresado.'],
                'alumnos.inactivar'    => ['label' => 'Marcar alumno como inactivo', 'nivel' => 3, 'desc' => 'Baja por abandono: sale de la cursada igual que un egresado y conserva todos sus datos.'],
                'alumnos.promocionar'  => ['label' => 'Promoción de alumnos', 'nivel' => 3, 'desc' => 'Pantalla de promocionar / pasar de año, con vista previa y aplicación.'],
                'alumnos.horario'      => ['label' => 'Asignar horario a un alumno', 'nivel' => 2, 'desc' => 'Editar la grilla de horarios de un alumno.'],
            ],
        ],
        'cursos' => [
            'grupo' => 'Cursos, materias y horarios',
            'items' => [
                'cursos.ver'           => ['label' => 'Ver cursos, divisiones y materias', 'nivel' => 1, 'desc' => 'Listados del módulo Cursos y sus catálogos.'],
                'cursos.crear'         => ['label' => 'Crear cursos', 'nivel' => 3, 'desc' => 'Altas de curso (año/división).'],
                'cursos.editar'        => ['label' => 'Editar cursos y divisiones', 'nivel' => 3, 'desc' => 'Cambiar datos de un curso, divisiones y sus asignaciones.'],
                'cursos.eliminar'      => ['label' => 'Eliminar cursos y materias', 'nivel' => 3, 'desc' => 'Bajas de cursos y de materias.'],
                'cursos.sync'          => ['label' => 'Sincronizar cursos', 'nivel' => 3, 'desc' => 'Utilidad de sincronización masiva de cursos.'],
                'materias.editar'      => ['label' => 'Editar materias', 'nivel' => 3, 'desc' => 'Alta, edición y reemplazo de materias por año.'],
                'horario.editar'       => ['label' => 'Editar horarios de curso', 'nivel' => 2, 'desc' => 'Cargar la grilla de horarios de una división (en el ámbito del rol).'],
                'ciclo.promocionar'    => ['label' => 'Promover ciclo lectivo', 'nivel' => 3, 'desc' => 'Cierre de año, promoción de ciclo y habilitación de años.'],
            ],
        ],
        'calificaciones' => [
            'grupo' => 'Calificaciones',
            'items' => [
                'calificaciones.ver'    => ['label' => 'Ver calificaciones y promedios', 'nivel' => 1, 'desc' => 'Notas, promedios y resumen por curso/materia.'],
                'calificaciones.editar' => ['label' => 'Cargar y editar notas', 'nivel' => 1, 'desc' => 'Planilla de notas y guardado por lote (en el ámbito del rol).'],
                'calificaciones.ventana'=> ['label' => 'Configurar la ventana de nota final', 'nivel' => 3, 'desc' => 'Abrir, cerrar y fijar el alcance de la mesa de examen.'],
            ],
        ],
        'asistencia' => [
            'grupo' => 'Asistencia',
            'items' => [
                'asistencia.ver'       => ['label' => 'Ver asistencia', 'nivel' => 1, 'desc' => 'Planillas, historial, inasistencias y estadísticas.'],
                'asistencia.editar'    => ['label' => 'Tomar y corregir asistencia', 'nivel' => 1, 'desc' => 'Cargar una clase, editarla o eliminarla (en el ámbito del rol). El rango Profesor nace con el alcance de escritura en "ninguno": ve la asistencia pero no la toma.'],
                'asistencia.justificar'=> ['label' => 'Justificar inasistencias', 'nivel' => 2, 'desc' => 'Crear y editar justificaciones.'],
            ],
        ],
        'profesores' => [
            'grupo' => 'Profesores',
            'items' => [
                'profesores.ver'      => ['label' => 'Ver profesores', 'nivel' => 1, 'desc' => 'Listado, fichas y cadena de suplencias.'],
                'profesores.editar'   => ['label' => 'Crear y editar profesores', 'nivel' => 3, 'desc' => 'Altas (manuales o desde usuarios) y edición de fichas.'],
                'profesores.suplencias'=> ['label' => 'Gestionar suplencias', 'nivel' => 3, 'desc' => 'Agregar o quitar eslabones de suplencia.'],
                'profesores.eliminar' => ['label' => 'Baja definitiva de profesores', 'nivel' => 3, 'desc' => 'Eliminar la ficha sin pasar por la papelera.'],
            ],
        ],
        'usuarios' => [
            'grupo' => 'Usuarios y roles',
            'items' => [
                'usuarios.ver'      => ['label' => 'Ver usuarios', 'nivel' => 2, 'desc' => 'Listado del módulo Usuarios y sus asignaciones.'],
                'usuarios.editar'   => ['label' => 'Editar usuarios', 'nivel' => 2, 'desc' => 'Datos básicos y asignaciones de cursos de una cuenta.'],
                'usuarios.crear'    => ['label' => 'Crear usuarios', 'nivel' => 3, 'desc' => 'Altas de cuentas con rol.'],
                'usuarios.aprobar'  => ['label' => 'Aprobar cuentas pendientes', 'nivel' => 3, 'desc' => 'Ver y verificar registros públicos.'],
                'usuarios.rol'      => ['label' => 'Cambiar el rol de un usuario', 'nivel' => 3, 'desc' => 'Asignar o modificar el rol de una cuenta existente.'],
                'usuarios.clave_temporal' => ['label' => 'Generar una clave temporal', 'nivel' => 3, 'desc' => 'Resetear la contraseña de una cuenta para entregársela en persona.'],
                'usuarios.eliminar' => ['label' => 'Eliminar usuarios', 'nivel' => 3, 'desc' => 'Baja de cuentas del sistema.'],
                'roles.configurar'  => ['label' => 'Crear y editar roles', 'nivel' => 3, 'desc' => 'Pestaña "Roles y permisos" (solo administrador y jefe de preceptores).'],
            ],
        ],
        'documentos' => [
            'grupo' => 'Documentos',
            'items' => [
                'documentos.ver'       => ['label' => 'Ver el listado de documentos', 'nivel' => 1, 'desc' => 'Archivo de documentos del colegio, filtrado por rol.'],
                'documentos.descargar' => ['label' => 'Descargar documentos', 'nivel' => 1, 'desc' => 'Botón de descarga de cada documento.'],
                'documentos.imprimir'  => ['label' => 'Imprimir documentos', 'nivel' => 1, 'desc' => 'Abrir la vista de impresión de un documento.'],
                'documentos.subir'     => ['label' => 'Subir archivos nuevos', 'nivel' => 2, 'desc' => 'Cargar un documento al archivo con su título y alcance.'],
                'documentos.editar'    => ['label' => 'Editar documentos', 'nivel' => 2, 'desc' => 'Cambiar título, tipo y roles autorizados; reemplazar el archivo.'],
                'documentos.eliminar'  => ['label' => 'Eliminar documentos', 'nivel' => 2, 'desc' => 'Borrar un documento del archivo.'],
            ],
        ],
        'salida' => [
            'grupo' => 'Impresión, exportación y manuales',
            'items' => [
                'impresion.usar'    => ['label' => 'Imprimir y exportar vistas', 'nivel' => 1, 'desc' => 'Fichas, planillas de notas, horarios y listados en pantalla de impresión o PDF.'],
                'historial.exportar'=> ['label' => 'Exportar el historial', 'nivel' => 3, 'desc' => 'Botón "Exportar" del módulo Historial (Excel, CSV o JSON). Solo el rol Admin lo tiene.'],
                'manuales.ver'      => ['label' => 'Abrir los manuales', 'nivel' => 1, 'desc' => 'Manual de usuario en pantalla o descargado.'],
                'manuales.programador' => ['label' => 'Abrir el manual del programador', 'nivel' => 4, 'desc' => 'Documentación técnica: estructura del sistema, base de datos y despliegue.'],
            ],
        ],
        'sistema' => [
            'grupo' => 'Panel, historial y sistema',
            'items' => [
                'dashboard.ver'   => ['label' => 'Ver el panel de inicio', 'nivel' => 1, 'desc' => 'Tarjetas y gráficos del Dashboard.'],
                'historial.ver'   => ['label' => 'Ver el historial de actividad', 'nivel' => 3, 'desc' => 'Auditoría de cambios del sistema.'],
                'historial.limpiar' => ['label' => 'Limpiar el historial de actividad', 'nivel' => 3, 'desc' => 'Botón "Limpiar historial" del módulo Historial: deja la auditoría vacía. Antes de borrar guarda una copia .sql en el servidor. Solo el rol Admin lo tiene.'],
                // Las tres claves de respaldos son del Admin y de nadie más: ver la
                // base, bajarla o pisarla. Están descriptas así en la pantalla.
                'backup.usar'     => ['label' => 'Descargar respaldos', 'nivel' => 3, 'desc' => 'Diagnóstico de la base y descarga del .sql. Solo el rol Admin lo tiene.'],
                'backup.restaurar'=> ['label' => 'Restaurar desde un respaldo', 'nivel' => 3, 'desc' => 'Importar un .sql: reemplaza los datos de la base. Solo el rol Admin lo tiene.'],
                'sistema.cobertura' => ['label' => 'Ver la revisión de cobertura', 'nivel' => 3, 'desc' => 'Panel de huecos que la base no rechaza: cursos que nadie puede modificar, cuentas sin curso, docentes con horario y sin cuenta.'],
                'ficha.datos_personales' => ['label' => 'Ver datos personales completos', 'nivel' => 3, 'desc' => 'DNI, domicilio y teléfono en fichas de profesores y en el listado de cuentas. Sin este permiso se ve la versión reducida.'],
            ],
        ],
    ];

    /**
     * Con qué módulo del menú y con qué área de datos va cada grupo de acciones.
     * Es el mapa que usa la pantalla "Roles y permisos" para mostrar un solo
     * bloque por módulo: visibilidad en el menú + qué ve + qué modifica + acciones.
     * `area` null = acciones transversales que no tienen datos que acotar.
     * Los tres ids de "Usuarios y roles" son cuentas del mismo módulo: preceptores,
     * jefatura y administración de cuentas se ocultan o se muestran juntas.
     */
    public const BLOQUES = [
        'alumnos'        => ['area' => 'alumnos',        'modulos' => ['alumnos']],
        'cursos'         => ['area' => 'cursos',         'modulos' => ['cursos']],
        'calificaciones' => ['area' => 'calificaciones', 'modulos' => ['calificaciones']],
        'asistencia'     => ['area' => 'asistencia',     'modulos' => ['asistencia']],
        'profesores'     => ['area' => 'profesores',     'modulos' => ['profesores']],
        'usuarios'       => ['area' => 'usuarios',       'modulos' => ['preceptores', 'jefe-preceptores', 'usuarios']],
        'documentos'     => ['area' => 'documentos',     'modulos' => ['documentos']],
        'salida'         => ['area' => null,             'modulos' => []],
        'sistema'        => ['area' => null,             'modulos' => ['dashboard', 'historial']],
    ];

    /**
     * Permisos que solo pueden vivir en un rol de sistema. La gestión de roles
     * queda en manos del administrador y del jefe de preceptores (assertAcceso
     * del controlador solo admite esos dos), así que un rol personalizado que lo
     * tuviera marcaría la casilla pero no podría usarla.
     */
    public const SOLO_SISTEMA = ['roles.configurar'];

    /**
     * Lo mínimo que el rol Admin conserva siempre. Desde que los roles del
     * sistema se editan como cualquiera, la pantalla puede desmarcar todo; si eso
     * le pasara al Admin, la única cuenta que puede corregir roles quedaría afuera
     * de su propia pantalla y la escuela no tendría cómo volver atrás.
     */
    public const PISO_ADMIN = [
        'roles.configurar', 'usuarios.ver', 'usuarios.editar', 'usuarios.clave_temporal',
    ];

    /**
     * Roles que de verdad tienen la gestión de roles. Los demás pierden esa
     * clave aunque el rango la cubra: coincide con ROLES_PERMITIDOS del
     * controlador, así la casilla de la pantalla refleja el acceso real.
     */
    public const ROLES_GESTION = [RoleHierarchy::ADMIN, RoleHierarchy::JEFE_PRECEPTORES];

    /**
     * Acciones que son de la cuenta Admin y de nadie más: las que meten mano a la
     * base entera (bajarla, reemplazarla, vaciar la auditoría) y la exportación del
     * historial, que la escuela decidió que salga por una sola mano. Ni por rango,
     * ni a mano, ni con un rol personalizado de rango 5. Se filtran
     * en base(), en efectivos() y en normalizarLista(), y las rutas exigen
     * role:admin por las dudas.
     */
    public const SOLO_ADMIN = ['backup.usar', 'backup.restaurar', 'historial.limpiar', 'historial.exportar'];

    /**
     * Permisos que trae un rol de sistema por su rango, ya sin los exclusivos de
     * otros roles. Es la base que pinta la pantalla de roles: de ahí para abajo.
     * Los respaldos se caen salvo que el rol sea el Admin, aunque el rango los
     * cubra: descargar o reemplazar la base es una sola cuenta del sistema.
     */
    public static function base(?string $rol): array
    {
        $claves = self::conNivelHasta(RoleHierarchy::level($rol));
        if (!in_array($rol, self::ROLES_GESTION, true)) {
            $claves = array_values(array_diff($claves, self::SOLO_SISTEMA));
        }
        if ($rol !== RoleHierarchy::ADMIN) {
            $claves = array_values(array_diff($claves, self::SOLO_ADMIN));
        }
        return $claves;
    }

    /**
     * Rutas fuera del grupo autenticado: no tienen usuario todavía, así que
     * EnsurePermiso ni corre sobre ellas. Están listadas para que el inventario
     * de rutas sin permiso sea siempre vacío y una ruta nueva y descatalogada
     * salte enseguida, en vez de perderse entre las que son públicas por diseño.
     */
    private const PUBLICAS = [
        'GET:ping', 'POST:login', 'POST:register',
        'POST:password/forgot', 'POST:password/reset',
    ];

    /**
     * Rutas siempre permitidas a cualquier cuenta autenticada: sin ellas no se
     * puede ni leer el propio perfil, cerrar sesión o dibujar el menú.
     */
    private const SIEMPRE = [
        'GET:me', 'GET:me/ambito', 'POST:logout', 'PUT:profile',
        'POST:profile/avatar', 'DELETE:profile/avatar', 'GET:avatars',
        'GET:auth/sesiones', 'POST:auth/sesiones/cerrar', 'POST:auth/confirm-password',
        'GET:datos/sello',
        'GET:roles', 'GET:roles/detalle',
    ];

    /**
     * Mapa ruta -> permiso. La clave es "METODO:camino" con el camino normalizado
     * (los números se reemplazan por {n} y las divisiones por {d}).
     */
    private const RUTAS = [
        'POST:alumnos'                          => 'alumnos.crear',
        'GET:alumnos'                           => 'alumnos.ver',
        'GET:alumnos/padron/excel'              => 'alumnos.padron',
        'GET:alumnos/importar/plantilla'        => 'alumnos.importar',
        'POST:alumnos/importar/preview'         => 'alumnos.importar',
        'POST:alumnos/importar/reporte'         => 'alumnos.importar',
        'POST:alumnos/importar/aplicar'         => 'alumnos.importar',
        'GET:alumnos/{n}'                       => 'alumnos.ver',
        'PUT:alumnos/{n}'                       => 'alumnos.editar',
        'DELETE:alumnos/{n}'                    => 'alumnos.eliminar',
        'POST:alumnos/{n}/egresar'              => 'alumnos.egresar',
        'POST:alumnos/{n}/inactivar'            => 'alumnos.inactivar',
        'PUT:alumnos/{n}/horario'               => 'alumnos.horario',
        'GET:alumnos/{n}/horario'               => 'alumnos.ver',
        'GET:alumnos/{n}/calificaciones'        => 'calificaciones.ver',
        'GET:alumnos/{n}/anios-con-notas'       => 'calificaciones.ver',
        'GET:alumnos/{n}/promedios'             => 'calificaciones.ver',
        'POST:alumnos/promocionar'              => 'alumnos.promocionar',
        'POST:alumnos/promocionar/preview'      => 'alumnos.promocionar',
        'POST:alumnos/promocionar/aplicar'      => 'alumnos.promocionar',
        'PUT:alumnos/{n}/calificaciones'        => 'calificaciones.editar',

        'GET:calificaciones/resumen'            => 'calificaciones.ver',
        'GET:calificaciones/por-curso-materia'  => 'calificaciones.ver',
        'GET:calificaciones/ventana'            => 'calificaciones.ver',
        'PUT:calificaciones/batch'              => 'calificaciones.editar',
        'POST:calificaciones/ventana'           => 'calificaciones.ventana',
        'POST:calificaciones/ventana/cerrar'    => 'calificaciones.ventana',

        'GET:cursos'                            => 'cursos.ver',
        'POST:cursos'                           => 'cursos.crear',
        'POST:cursos/sync'                      => 'cursos.sync',
        'PATCH:cursos/{n}'                      => 'cursos.editar',
        'DELETE:cursos/{n}'                     => 'cursos.eliminar',
        'GET:cursos/{n}/{d}/horario'            => 'cursos.ver',
        'PUT:cursos/{n}/{d}/horario'            => 'horario.editar',
        'GET:horarios/{n}/{d}'                  => 'cursos.ver',
        'PUT:horarios/{n}/{d}'                  => 'horario.editar',
        'GET:divisiones'                        => 'cursos.ver',
        'PUT:divisiones'                        => 'cursos.editar',
        'PUT:divisiones/bulk'                   => 'cursos.editar',
        'GET:materias/todas'                    => 'cursos.ver',
        'GET:anios/{n}/materias'                => 'cursos.ver',
        'PUT:anios/{n}/materias'                => 'materias.editar',
        'PUT:materias/{n}'                      => 'materias.editar',
        'DELETE:materias/{n}'                   => 'cursos.eliminar',
        'POST:ciclo/promocion'                  => 'ciclo.promocionar',
        'POST:admin/years/enable'               => 'ciclo.promocionar',

        'GET:profesores'                        => 'profesores.ver',
        'GET:profesores/mapping'                => 'profesores.ver',
        'GET:profesores/en-horarios'            => 'profesores.ver',
        'GET:profesores/{n}'                    => 'profesores.ver',
        'GET:profesores/{n}/suplencias'         => 'profesores.ver',
        'POST:profesores'                       => 'profesores.editar',
        'POST:profesores/from-users'            => 'profesores.editar',
        'PUT:profesores/{n}'                    => 'profesores.editar',
        'DELETE:profesores/{n}/force'           => 'profesores.eliminar',
        'POST:profesores/{n}/suplencias'        => 'profesores.suplencias',
        'DELETE:profesores/suplencias/{n}'      => 'profesores.suplencias',

        'GET:asistencia/cursos'                 => 'asistencia.ver',
        'GET:asistencia/clases'                 => 'asistencia.ver',
        'GET:asistencia/clases/{n}'             => 'asistencia.ver',
        'GET:asistencia/historial'              => 'asistencia.ver',
        'GET:asistencia/inasistencias'          => 'asistencia.ver',
        'GET:asistencia/justificaciones'        => 'asistencia.ver',
        'GET:asistencia/estadisticas'           => 'asistencia.ver',
        'POST:asistencia/clases'                => 'asistencia.editar',
        'PUT:asistencia/clases/{n}'             => 'asistencia.editar',
        'DELETE:asistencia/clases/{n}'          => 'asistencia.editar',
        'POST:asistencia/justificaciones'       => 'asistencia.justificar',
        'PUT:asistencia/justificaciones/{n}'    => 'asistencia.justificar',

        'GET:admin/users'                       => 'usuarios.ver',
        'GET:admin/users/pending'               => 'usuarios.aprobar',
        'GET:admin/users/{n}/assignments'       => 'usuarios.ver',
        'PUT:admin/users/{n}/assignments'       => 'usuarios.editar',
        'PUT:admin/users/{n}'                   => 'usuarios.editar',
        'POST:admin/users/{n}/clave-temporal'   => 'usuarios.clave_temporal',
        'POST:admin/users'                      => 'usuarios.crear',
        'PUT:admin/users/{n}/verify'            => 'usuarios.aprobar',
        'PUT:admin/users/{n}/role'              => 'usuarios.rol',
        'DELETE:admin/users/{n}'                => 'usuarios.eliminar',
        'GET:admin/roles'                       => 'roles.configurar',
        'POST:admin/roles'                      => 'roles.configurar',
        'PUT:admin/roles/{rol}'                 => 'roles.configurar',
        'DELETE:admin/roles/{rol}'              => 'roles.configurar',

        'GET:documentos'                        => 'documentos.ver',
        'GET:documentos/{n}/download'           => 'documentos.descargar',
        'GET:documentos/{n}/print'              => 'documentos.imprimir',
        'POST:documentos'                       => 'documentos.subir',
        'PUT:documentos/{n}'                    => 'documentos.editar',
        'DELETE:documentos/{n}'                 => 'documentos.eliminar',

        'GET:dashboard/top-performers'          => 'dashboard.ver',
        'GET:dashboard/resumen'                 => 'dashboard.ver',
        'GET:dashboard/preceptores'             => 'dashboard.ver',
        'GET:historial'                         => 'historial.ver',
        'POST:historial/refresh'                => 'historial.ver',
        // Ahora la descarga la arma el servidor, así que sí hay ruta que frenar.
        'GET:historial/export'                  => 'historial.exportar',
        // Vaciar la auditoría es tan destructivo como pisar la base: SOLO_ADMIN.
        'DELETE:historial'                      => 'historial.limpiar',
        // Ver y bajar la base es SOLO_ADMIN: solo el rol Admin conserva estas claves.
        'GET:backups/sql/check'                 => 'backup.usar',
        'GET:backups/sql/download'              => 'backup.usar',
        'GET:cobertura'                         => 'sistema.cobertura',
        'POST:backups/sql/import'               => 'backup.restaurar',

        // Vistas imprimibles: el ticket se pide acá y es lo que abre el PDF.
        // Sin este permiso no hay ticket, así que tampoco impresión ni export.
        'POST:print/ticket'                     => 'impresion.usar',

        // Documentación del sistema. El manual del programador filtra por rol
        // dentro del controlador.
        'GET:manuales'                          => 'manuales.ver',
        'GET:manuales/usuario'                  => 'manuales.ver',
        'GET:manuales/programador'              => 'manuales.programador',
    ];

    /**
     * Claves que no cuelgan de ninguna ruta: se aplican sobre un botón o sobre el
     * contenido de una ficha, no sobre el acceso a un endpoint. Siguen decidiendo
     * lo que la cuenta puede hacer; solo no pasan por EnsurePermiso.
     */
    public const SIN_RUTA = [
        'ficha.datos_personales'  => 'Se evalúa dentro de AuthorizesScope al armar la ficha; el endpoint es el mismo de siempre.',
    ];

    /** Plantillas: roles personalizados listos para crear desde la pantalla. */
    private const PLANTILLAS = [
        'profesor' => [
            'nombre'    => 'Profesor',
            'nivel'     => 1,
            'descripcion' => 'Carga las notas de sus cursos asignados y ve la asistencia, sin tomarla.',
            'permitidos' => [
                'alumnos.ver', 'cursos.ver', 'calificaciones.ver', 'calificaciones.editar',
                'asistencia.ver', 'profesores.ver',
                'documentos.ver', 'documentos.descargar', 'documentos.imprimir',
                'impresion.usar', 'manuales.ver', 'dashboard.ver',
            ],
        ],
        'preceptor' => [
            'nombre'    => 'Preceptor',
            'nivel'     => 2,
            'descripcion' => 'Gestiona alumnos, horarios y documentos de sus cursos; edita profesores asignados.',
            'permitidos' => [
                'alumnos.ver', 'alumnos.padron', 'alumnos.editar', 'alumnos.horario',
                'cursos.ver', 'horario.editar',
                'calificaciones.ver', 'calificaciones.editar',
                'asistencia.ver', 'asistencia.editar', 'asistencia.justificar',
                'profesores.ver', 'profesores.editar',
                'usuarios.ver', 'usuarios.editar',
                'documentos.ver', 'documentos.descargar', 'documentos.imprimir',
                'documentos.subir', 'documentos.editar', 'documentos.eliminar',
                'impresion.usar', 'manuales.ver', 'dashboard.ver',
            ],
        ],
        'gestion' => [
            'nombre'    => 'Gestión académica',
            'nivel'     => 3,
            'descripcion' => 'Nivel jefe de preceptores, sin administración de usuarios ni de roles.',
            'permitidos' => [
                'alumnos.ver', 'alumnos.padron', 'alumnos.crear', 'alumnos.importar', 'alumnos.editar', 'alumnos.eliminar',
                'alumnos.egresar', 'alumnos.inactivar', 'alumnos.promocionar', 'alumnos.horario',
                'cursos.ver', 'cursos.crear', 'cursos.editar', 'cursos.eliminar', 'cursos.sync',
                'materias.editar', 'horario.editar', 'ciclo.promocionar',
                'calificaciones.ver', 'calificaciones.editar', 'calificaciones.ventana',
                'asistencia.ver', 'asistencia.editar', 'asistencia.justificar',
                'profesores.ver', 'profesores.editar', 'profesores.suplencias', 'profesores.eliminar',
                'documentos.ver', 'documentos.descargar', 'documentos.imprimir',
                'documentos.subir', 'documentos.editar', 'documentos.eliminar',
                'impresion.usar', 'manuales.ver', 'ficha.datos_personales',
                'dashboard.ver', 'historial.ver',
                'sistema.cobertura',
            ],
        ],
    ];

    /** Catálogo completo agrupado, listo para pintar en la pantalla. */
    public static function catalogo(): array
    {
        $out = [];
        foreach (self::CATALOGO as $grupo => $datos) {
            $items = [];
            foreach ($datos['items'] as $clave => $meta) {
                $items[] = ['clave' => $clave] + $meta;
            }
            $out[] = ['grupo' => $datos['grupo'], 'items' => $items];
        }
        return $out;
    }

    /**
     * Catálogo agrupado junto a su módulo del menú y su área de datos: es el
     * orden en que la pantalla "Roles y permisos" arma un bloque por módulo.
     */
    public static function bloques(): array
    {
        $out = [];
        foreach (self::CATALOGO as $grupo => $datos) {
            $items = [];
            foreach ($datos['items'] as $clave => $meta) {
                $items[] = ['clave' => $clave] + $meta;
            }
            $bloque = self::BLOQUES[$grupo] ?? ['area' => null, 'modulos' => []];
            $modulos = [];
            foreach ($bloque['modulos'] as $id) {
                $modulos[] = ['id' => $id, 'nombre' => Alcances::MODULOS[$id] ?? $id];
            }
            $out[] = [
                'id'      => $grupo,
                'nombre'  => $datos['grupo'],
                'area'    => $bloque['area'],
                'modulos' => $modulos,
                'items'   => $items,
            ];
        }
        return $out;
    }

    /** Todas las claves válidas del catálogo. */
    public static function claves(): array
    {
        return array_keys(self::indice());
    }

    /** Nivel por defecto de un permiso (0 si no está en el catálogo). */
    public static function nivelBase(string $clave): int
    {
        $indice = self::indice();
        return $indice[$clave]['nivel'] ?? 0;
    }

    /** Catalogo aplanado clave => {label, nivel, desc, grupo}, calculado una sola vez. */
    private static function indice(): array
    {
        static $indice = null;
        if ($indice !== null) {
            return $indice;
        }

        $indice = [];
        foreach (self::CATALOGO as $grupo => $datos) {
            foreach ($datos['items'] as $clave => $meta) {
                $indice[$clave] = $meta + ['grupo' => $datos['grupo']];
            }
        }
        return $indice;
    }

    /** Plantillas disponibles para crear un rol nuevo. */
    public static function plantillas(): array
    {
        $out = [];
        foreach (self::PLANTILLAS as $id => $p) {
            $out[] = ['id' => $id] + $p;
        }
        return $out;
    }

    /** Filtro de claves: elimina desconocidas y duplicados. */
    public static function sanear(?array $claves): array
    {
        $validas = [];
        foreach ((array) $claves as $c) {
            $c = trim((string) $c);
            if ($c !== '' && self::nivelBase($c) > 0) {
                $validas[$c] = true;
            }
        }
        return array_values(array_keys($validas));
    }

    /** true si el rol es un rol personalizado (creado en Roles y permisos). */
    public static function esPersonalizado(?string $rol): bool
    {
        return RoleHierarchy::esPersonalizado($rol);
    }

    /**
     * true si el rol no necesita filtro: rol de sistema sin fila, o con fila sin
     * cambios. Mientras devuelva true el request sigue exactamente la regla de
     * siempre (middleware role:), sin riesgo de regresión en las cuentas actuales.
     */
    public static function sinFiltro(?string $rol): bool
    {
        if ($rol === null || $rol === '') return true;
        if (self::esPersonalizado($rol)) return false;
        $fila = RolConfig::registry()[$rol] ?? null;
        if ($fila === null) return true;
        return $fila['negados'] === [] && $fila['permitidos'] === []
            && ($fila['alcance'] ?? []) === [] && ($fila['modulos'] ?? null) === null;
    }

    /**
     * Claves efectivamente permitidas al rol, o null si el rol no tiene ninguna
     * restricción registrada (los roles de sistema sin fila).
     */
    public static function efectivos(?string $rol): ?array
    {
        if (self::sinFiltro($rol)) {
            return null;
        }

        $fila      = RolConfig::registry()[$rol] ?? null;
        $negados   = $fila['negados'] ?? [];
        $guardados = self::sanear($fila['permitidos'] ?? []);

        if ($guardados !== []) {
            // El rol tiene su lista propia guardada, sea del sistema o personalizado:
            // esa lista es la lista completa, no se le suma nada encima.
            $permitidos = $guardados;
        } elseif ($fila && $fila['tipo'] === 'sistema') {
            // Fila vieja, con solo quitas: la base del rango sigue mandando.
            $permitidos = self::base($rol);
        } else {
            $permitidos = [];
        }

        // La gestión de roles no se cuela por ninguna lista: solo admin y jefe.
        if (!in_array($rol, self::ROLES_GESTION, true)) {
            $permitidos = array_diff($permitidos, self::SOLO_SISTEMA);
        }

        // Ni siquiera una lista guardada de antes deja respaldos en un rol que no
        // es el Admin: la pantalla ya no los ofrece, y acá se ignoran si vienen.
        if ($rol !== RoleHierarchy::ADMIN) {
            $permitidos = array_diff($permitidos, self::SOLO_ADMIN);
        }

        return array_values(array_diff(array_unique($permitidos), $negados));
    }

    /**
     * Lista de acciones que queda guardada para un rol, con las reglas que valen
     * para todos los roles por igual:
     *  - nadie regala permisos de un rango mayor al propio (frena la escalada);
     *  - la gestión de roles no se reparte fuera de Admin y Jefe de Preceptores;
     *  - los respaldos no salen del Admin, ni siquiera en un rol rango 5;
     *  - Admin y Jefe no pueden quitarse la pantalla de roles, y el Admin conserva
     *    el piso mínimo para poder corregirse (es la única cuenta que lo puede todo).
     */
    public static function normalizarLista(?string $rol, array $claves, int $nivelActor): array
    {
        $exclusivos = array_flip(self::SOLO_SISTEMA);
        $respaldos  = array_flip(self::SOLO_ADMIN);
        $esGestion  = in_array($rol, self::ROLES_GESTION, true);
        $esAdmin    = $rol === RoleHierarchy::ADMIN;

        $out = [];
        foreach (self::sanear($claves) as $clave) {
            if (isset($exclusivos[$clave]) && !$esGestion) continue;
            if (isset($respaldos[$clave]) && !$esAdmin) continue;
            if (self::nivelBase($clave) <= $nivelActor) $out[] = $clave;
        }

        // El que gestiona roles no puede cerrar su propia puerta de entrada.
        if ($esGestion) $out = array_merge($out, self::SOLO_SISTEMA);

        if ($esAdmin) $out = array_merge($out, self::PISO_ADMIN);

        return array_values(array_unique($out));
    }

    /** Todos los permisos que trae un nivel de RoleHierarchy. */
    public static function conNivelHasta(int $nivel): array
    {
        $out = [];
        foreach (self::indice() as $clave => $meta) {
            if ($meta['nivel'] <= $nivel) $out[] = $clave;
        }
        return $out;
    }

    /** ¿El rol puede hacer $clave? */
    public static function puede(?string $rol, string $clave): bool
    {
        $efectivos = self::efectivos($rol);
        if ($efectivos === null) {
            // Rol de sistema sin filtro: se respeta la base de su rango.
            return in_array($clave, self::base($rol), true);
        }
        return in_array($clave, $efectivos, true);
    }

    /**
     * Permiso que cubre una request. Devuelve null si la ruta no está en el mapa
     * (por ejemplo las rutas de sesión y perfil, siempre permitidas).
     */
    public static function rutaPermiso(string $metodo, string $camino): ?string
    {
        $key = strtoupper($metodo) . ':' . self::normalizar($camino);

        if (in_array($key, self::SIEMPRE, true)) {
            return null;
        }

        return self::RUTAS[$key] ?? null;
    }

    /** Clave del catálogo que exige un request, o null si la ruta no está catalogada. */
    public static function claveDel(Request $request): ?string
    {
        // La API monta las rutas bajo /api; el mapa usa el camino relativo al grupo.
        $camino = preg_replace('#^api/#', '', trim($request->path(), '/'));
        return self::rutaPermiso($request->getMethod(), $camino);
    }

    /** true si la request cae en una ruta catalogada o de las siempre permitidas. */
    public static function rutaConocidaRequest(Request $request): bool
    {
        $camino = preg_replace('#^api/#', '', trim($request->path(), '/'));
        return self::rutaConocida($request->getMethod(), $camino);
    }

    /** true si la ruta está catalogada, es de las siempre permitidas o es pública. */
    public static function rutaConocida(string $metodo, string $camino): bool
    {
        $key = strtoupper($metodo) . ':' . self::normalizar($camino);
        return in_array($key, self::SIEMPRE, true)
            || in_array($key, self::PUBLICAS, true)
            || isset(self::RUTAS[$key]);
    }

    /** "alumnos/63/calificaciones" -> "alumnos/{n}/calificaciones" */
    private static function normalizar(string $camino): string
    {
        $camino = trim($camino, '/');
        if ($camino === '') return '';

        $partes = explode('/', $camino);

        // En "horarios/{anio}/{division}" y "cursos/{anio}/{division}/horario" el
        // tercer segmento es la división, y en la escuela las divisiones son
        // números ('1'..'9'). Sin esta excepción se normalizaban como {n} y la
        // clave del catálogo ({n}/{d}) no aparecía nunca: las casillas "Ver
        // cursos" y "Editar horarios" quedaban sin efecto sobre estos endpoints,
        // y un rol personalizado los tenía bloqueados por rutas "no catalogadas".
        $primero = strtolower($partes[0] ?? '');
        $esGrilla = ($primero === 'horarios' && count($partes) === 3)
            || ($primero === 'cursos' && count($partes) === 4 && strtolower($partes[3]) === 'horario');
        // En "admin/roles/{rol}" el tercer segmento es la clave del rol ('preceptor',
        // 'biblioteca'), no un id: como texto quedaba literal y la clave del catálogo
        // no aparecía nunca, así que la casilla "Configurar roles" frenaba el listado
        // y el alta pero no la edición ni el borrado.
        $esRol = $primero === 'admin' && strtolower($partes[1] ?? '') === 'roles' && count($partes) === 3;

        foreach ($partes as $i => $p) {
            if ($p === '') continue;
            if ($esGrilla && $i === 2) {
                $partes[$i] = '{d}';
            } elseif ($esRol && $i === 2) {
                $partes[$i] = '{rol}';
            } elseif (is_numeric($p)) {
                $partes[$i] = '{n}';
            } elseif (preg_match('/^\d{1,2}[a-zA-Z]{1,2}$/', $p)) {
                // División de curso ("1A", "2B", "3ra" no: 3 letras).
                $partes[$i] = '{d}';
            } else {
                $partes[$i] = strtolower($p);
            }
        }

        return implode('/', $partes);
    }
}
