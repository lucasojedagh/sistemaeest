<?php

namespace Tests\Unit;

use App\Support\Alcances;
use App\Support\RoleHierarchy;
use PHPUnit\Framework\TestCase;

// El mapa de alcance por rol (ver / modificar / módulos), sin tocar la BD.
// Sin filas en roles_config cada rol usa su defecto: la instalación nueva tiene
// que comportarse como siempre, con las dos reglas que se pidieron:
// el preceptor ve todos los cursos y solo modifica los asignados, y el profesor
// trabaja únicamente dentro de los suyos.
class AlcancesTest extends TestCase
{
    public function test_los_rol_de_gestion_ve_y_edita_todo(): void
    {
        foreach ([RoleHierarchy::ADMIN, RoleHierarchy::DIRECTIVO, RoleHierarchy::JEFE_PRECEPTORES, RoleHierarchy::PROSECRETARIA] as $rol) {
            foreach (array_keys(Alcances::AREAS) as $area) {
                $this->assertSame(Alcances::TODO, Alcances::ver($rol, $area), "{$rol}/{$area} ver");
                $this->assertSame(Alcances::TODO, Alcances::editar($rol, $area), "{$rol}/{$area} editar");
            }
        }
    }

    public function test_preceptor_ve_todo_y_edita_solo_lo_asignado(): void
    {
        $rol = RoleHierarchy::PRECEPTOR;
        foreach (array_keys(Alcances::AREAS) as $area) {
            $this->assertSame(Alcances::TODO, Alcances::ver($rol, $area), "{$area} ver");
            // Los documentos no cuelgan de un curso: el preceptor los edita todos.
            $esperado = $area === 'documentos' ? Alcances::TODO : Alcances::ASIGNADO;
            $this->assertSame($esperado, Alcances::editar($rol, $area), "{$area} editar");
        }
    }

    public function test_profesor_solo_trabaja_en_sus_cursos(): void
    {
        $rol = RoleHierarchy::PROFESOR;
        $this->assertSame(Alcances::ASIGNADO, Alcances::ver($rol, 'alumnos'));
        $this->assertSame(Alcances::ASIGNADO, Alcances::editar($rol, 'calificaciones'));
        $this->assertSame(Alcances::ASIGNADO, Alcances::editar($rol, 'asistencia'));
        foreach (['alumnos', 'cursos', 'profesores', 'usuarios'] as $area) {
            $this->assertSame(Alcances::NADA, Alcances::editar($rol, $area), "{$area} editar");
        }
        // Ver el archivo de documentos no depende de un curso.
        $this->assertSame(Alcances::TODO, Alcances::ver($rol, 'documentos'));
    }

    public function test_sin_rol_y_desconocidos_no_ven_nada(): void
    {
        foreach ([RoleHierarchy::SIN_ROL, 'rol_inventado', null] as $rol) {
            foreach (array_keys(Alcances::AREAS) as $area) {
                $this->assertSame(Alcances::NADA, Alcances::ver($rol, $area), "{$area} ver");
                $this->assertSame(Alcances::NADA, Alcances::editar($rol, $area), "{$area} editar");
            }
        }
    }

    public function test_un_area_sin_curso_no_ofrece_solo_asignados(): void
    {
        $this->assertSame([Alcances::TODO, Alcances::NADA], Alcances::opciones('documentos'));
        $this->assertSame([Alcances::TODO, Alcances::ASIGNADO, Alcances::NADA], Alcances::opciones('alumnos'));

        // Aunque alguien guarde 'asignados' en documentos, el defecto lo baja a 'ninguno'.
        $defecto = Alcances::defecto(RoleHierarchy::PROFESOR);
        $this->assertSame(Alcances::NADA, $defecto['usuarios']['editar']);
    }

    public function test_sanear_deja_solo_lo_que_se_desvia_del_defecto(): void
    {
        // El preceptor ya edita 'asignados' por defecto: no se guarda nada.
        $este = Alcances::sanear([
            'alumnos' => ['ver' => Alcances::TODO, 'editar' => Alcances::ASIGNADO],
            'cursos'  => ['ver' => Alcances::ASIGNADO],
        ], RoleHierarchy::PRECEPTOR);
        $this->assertSame(['cursos' => ['ver' => Alcances::ASIGNADO]], $este);

        // Valores inválidos o de áreas inexistentes se descartan.
        $this->assertSame([], Alcances::sanear([
            'alumnos'    => ['ver' => 'quiza'],
            'area_falsa' => ['ver' => Alcances::TODO],
        ], RoleHierarchy::PRECEPTOR));

        // 'asignados' no existe donde no hay curso.
        $this->assertSame([], Alcances::sanear(['documentos' => ['editar' => Alcances::ASIGNADO]], RoleHierarchy::PRECEPTOR));
    }

    public function test_un_guardado_devuelve_el_rol_a_su_defecto(): void
    {
        // Ocultar "ver" arrastra a "modificar": se guardan las dos, no queda una
        // regla a medio corregir que dependa del recorte para funcionar.
        $guardado = ['alumnos' => ['ver' => Alcances::NADA]];
        $mapa = Alcances::sanear($guardado, RoleHierarchy::PRECEPTOR);
        $restaurado = Alcances::sanear(Alcances::de(RoleHierarchy::PRECEPTOR), RoleHierarchy::PRECEPTOR);
        $this->assertSame([], $restaurado, 'volver a guardar el mapa efectivo no debe dejar diferencias');
        $this->assertSame(
            ['alumnos' => ['ver' => Alcances::NADA, 'editar' => Alcances::NADA]],
            $mapa
        );
    }

    // El bug que este test cierra: la pantalla dejaba armar "ve Solo los asignados"
    // con "modifica Todos". Se guardaba, pero el backend nunca lo aplicaba porque
    // la lectura frena antes; el rol quedaba con un permiso fantasma.
    public function test_modificar_no_puede_quedar_por_encima_de_ver(): void
    {
        // El profesor ve 'asignados' en alumnos: pedirle 'todos' en modificar baja
        // al nivel de ver, y se guarda solo la parte que realmente cambia (de 'nada'
        // a 'los suyos').
        $mapa = Alcances::sanear(
            ['alumnos' => ['ver' => Alcances::ASIGNADO, 'editar' => Alcances::TODO]],
            RoleHierarchy::PROFESOR
        );
        $this->assertSame(['alumnos' => ['editar' => Alcances::ASIGNADO]], $mapa);

        // Ocultar el área también baja la escritura que quedó arriba.
        $mapa = Alcances::sanear(
            ['calificaciones' => ['ver' => Alcances::NADA, 'editar' => Alcances::TODO]],
            RoleHierarchy::PRECEPTOR
        );
        $this->assertSame(
            ['calificaciones' => ['ver' => Alcances::NADA, 'editar' => Alcances::NADA]],
            $mapa
        );

        // Ampliar "ver" sí habilita la escritura más ancha.
        $mapa = Alcances::sanear(
            ['alumnos' => ['ver' => Alcances::TODO, 'editar' => Alcances::TODO]],
            RoleHierarchy::PROFESOR
        );
        $this->assertSame(
            ['alumnos' => ['ver' => Alcances::TODO, 'editar' => Alcances::TODO]],
            $mapa
        );

        // Y un mapa guardado a mano con el par suelto se lee ya recortado.
        $este = Alcances::recortar(['ver' => Alcances::ASIGNADO, 'editar' => Alcances::TODO]);
        $this->assertSame(Alcances::ASIGNADO, $este['editar']);
    }

    public function test_el_rol_personalizado_usa_el_defecto_de_su_nivel(): void
    {
        // Sin fila en roles_config un rol desconocido es nivel 0: nada.
        $this->assertSame(Alcances::NADA, Alcances::ver('auxiliar', 'alumnos'));
    }

    public function test_modulos_por_defecto_y_su_saneamiento(): void
    {
        $todos = array_keys(Alcances::MODULOS);
        $this->assertSame($todos, Alcances::modulosDefecto(RoleHierarchy::ADMIN));
        $this->assertSame([], Alcances::modulosDefecto(RoleHierarchy::SIN_ROL));
        $this->assertNotContains('usuarios', Alcances::modulosDefecto(RoleHierarchy::PRECEPTOR));

        // Igual al defecto -> null (el rol sigue sin pasar por el filtro).
        $this->assertNull(Alcances::sanearModulos(Alcances::modulosDefecto(RoleHierarchy::PRECEPTOR), RoleHierarchy::PRECEPTOR));

        // Recortar uno -> queda la lista sin ese módulo (ordenada), y solo con claves conocidas.
        $casi = array_values(array_diff(Alcances::modulosDefecto(RoleHierarchy::PRECEPTOR), ['documentos']));
        sort($casi);
        $lista = Alcances::sanearModulos(array_merge($casi, ['modulo_falso']), RoleHierarchy::PRECEPTOR);
        $this->assertSame($casi, $lista);
        $this->assertNotContains('modulo_falso', $lista);
    }

    public function test_el_catalogo_de_areas_tiene_todas_las_claves(): void
    {
        $areas = array_column(Alcances::catalogo(), 'area');
        $this->assertSame(array_keys(Alcances::AREAS), $areas);
        foreach (Alcances::catalogo() as $area) {
            $this->assertNotEmpty($area['opciones']);
            $this->assertArrayHasKey($area['area'], Alcances::defecto(RoleHierarchy::PROFESOR));
        }
    }
}
