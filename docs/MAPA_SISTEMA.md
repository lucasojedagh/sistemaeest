# MAPA DEL SISTEMA ENRO — del proceso al archivo

Cómo leer este mapa: cada proceso que ves en pantalla está desarmado en
**pantalla → archivos frontend → endpoint API → archivos backend → tablas**.
Los dominios son espejados: `src/modulos/<x>/` (frontend) ≈ `app/Dominios/<X>/` (backend).

```
FRONTEND                                    BACKEND
src/                                        app/
├── modulos/                                ├── Dominios/
│   ├── alumnos/       ←→ Alumnos/          │   ├── Alumnos/
│   ├── profesores/    ←→ Profesores/       │   ├── Profesores/
│   ├── preceptores/   ←→ Usuarios/         │   ├── Asistencia/
│   ├── asistencia/    ←→ Asistencia/       │   ├── Calificaciones/
│   ├── calificaciones ←→ Calificaciones/   │   ├── Cursos/
│   ├── cursos/        ←→ Cursos/           │   ├── Usuarios/
│   ├── usuarios/      ←→ Usuarios/         │   ├── Documentos/
│   ├── documentos/    ←→ Documentos/       │   └── Sistema/
│   ├── historial/     ←→ Sistema/          ├── Support/      (permisos, etiquetas, reglas)
│   ├── dashboard/     ←→ Sistema/          └── Http/
│   └── auth/          ←→ Usuarios/              ├── Middleware/ (AuthToken, roles)
└── compartido/                              └── Requests/   (validaciones)
    ├── api/        (llamadas HTTP)          routes/api.php   (todas las rutas)
    ├── lib/        (helpers)                routes/web.php   (imprimibles)
    ├── hooks/      (modales compartidos)    database/migrations/ (tablas)
    ├── styles/     (solo el CSS global)
    └── componentes/
        ├── Common/   (botones, tablas, vacíos)
        ├── layout/   (Navbar, buscador)
        └── modals/   (cuenta, registro, confirmar)
```

> **Dónde está cada cosa del frontend.** El CSS de una pantalla vive al lado de
> su componente (`modulos/alumnos/Alumnos.css`); en `compartido/styles/` sólo
> quedan `variables.css` y `base.css`, que son globales y los carga `index.css`.
> Los helpers —incluidos los de exportar CSV/JSON— están todos en
> `compartido/lib/`; ya no existe `compartido/utils/`.
> Los endpoint se escriben en el componente con `apiGet`/`apiPost` de
> `compartido/lib/api.js`; `compartido/api/` sólo reúne las de login y asistencia.

## Procesos principales

### 1. Pasar lista (asistencia diaria)
- Pantalla: `modulos/asistencia/Asistencia.jsx` → pestaña `TomarAsistencia.jsx`
- API: `GET /api/alumnos?anio=&division=` (lista del curso), `POST /api/asistencia/clases` (crea la clase), `PUT /api/asistencia/clases/{id}` (carga)
- Backend: `Dominios/Asistencia/AsistenciaController.php`
- Tablas: `clases`, `asistencias`, `asistencia_justificaciones`, `cursos`

### 2. Cargar notas y promedios
- Pantalla: `modulos/calificaciones/Calificaciones.jsx` → `DetalleCalificaciones*.jsx`, `FormularioCalificaciones.jsx`, `PlanillaModal.jsx`
- API: `GET/PUT /api/alumnos/{id}/calificaciones`, `PUT /api/calificaciones/batch`, `POST /api/calificaciones/ventana`
- Backend: `Dominios/Calificaciones/AlumnoCalificacionesController.php`, `VentanaNotaFinalController.php`
- Tablas: `alumno_calificaciones`, `alumno_promedios`, `alumno_materias_estado`, `ventana_nota_final`

### 3. Ver y editar horario de un curso
- Pantalla: `modulos/cursos/Cursos.jsx` → `ModalHorario.jsx` (ver + editar por popup)
- API: `GET/PUT /api/horarios/{anio}/{division}?grupo=`, `GET /api/cursos/{anio}/{division}/horario`
- Backend: `Dominios/Cursos/HorarioController.php` (+ `HorarioCelda.php`)
- Tablas: `horario_celdas` (slot h1..h9, grupo null/A/B), `cursos`, `materias`

### 4. Inscribir / editar un alumno (recursadas, intensificación, horario propio)
- Pantalla: `modulos/alumnos/Alumnos.jsx` → `AlumnoDetalle.jsx`, `AlumnoModal.jsx`, `HorarioAlumnoModal.jsx`
- API: `GET /api/alumnos`, `POST /api/alumnos`, `PUT/DELETE /api/alumnos/{id}`, `PUT /api/alumnos/{id}/horario`
- Descarga: `GET /api/alumnos/padron/excel` (`Support/PadronExcel.php`) → `lista_alumnos-DD-MM-AAAA.xlsx` con hojas PADRÓN y RESUMEN. La pantalla usa esta ruta; `/print/alumnos` quedó sin uso desde que se sacó el botón de imprimir.
- Backend: `Dominios/Alumnos/AlumnoController.php`, `AlumnoHorarioController.php`
- Tablas: `alumnos`, `alumno_direcciones`, `alumno_telefonos`, `alumno_responsables`, `alumno_recursandos`, `alumno_intensificaciones`, `alumno_horario_overrides` (+ cascadas: calificaciones, promedios, estados, asistencias)

### 5. Gestionar profesores (altas, bajas definitivas)
- Pantalla: `modulos/profesores/Profesores.jsx` → `ListaProfesores.jsx`, `ProfesoresDetalle.jsx`, `ProfesorFicha.jsx`
- API: `GET/POST /api/profesores`, `PUT /api/profesores/{id}`, `DELETE /api/profesores/{id}/force`, `POST /api/profesores/from-users`
- Backend: `Dominios/Profesores/ProfesorController.php`
- Tablas: `profesores`, `profesor_asignaciones`, `profesor_bajas`
- Nota: no hay papelera; la baja borra todo y libera horarios (`profesor_id` a NULL)

### 6. Usuarios, roles y permisos
- Pantalla: `modulos/usuarios/AdminUsers.jsx`, `modulos/preceptores/Preceptores.jsx`
- API: `/api/admin/users*`, `/api/roles`, `POST /api/auth/confirm-password`
- Backend: `Dominios/Usuarios/AdminUsersController.php`, `AuthApiController.php`
- Reglas: `Support/RoleHierarchy.php` (niveles), `Support/AuthorizesScope.php` (quién puede qué)
- Tablas: `users`, `user_anio_division`

### 7. Entrar al sistema (login/registro)
- Pantalla: `modulos/auth/` (`Login.jsx`, `Register.jsx`, guards, `AuthContext.jsx`)
- API: `POST /api/login`, `/api/register`, `/api/me`, `/api/logout`, `/password/*`
- Backend: `Dominios/Usuarios/AuthApiController.php`, `PasswordResetController.php`, `Http/Middleware/AuthToken.php`
- Sesión: token `api_token` (header `Authorization: Bearer`), 401 = volver al login

### 8. Historial de cambios
- Pantalla: `modulos/historial/Historial.jsx`
- API: `GET /api/historial` (+ `POST /api/historial/refresh`)
- Backend: `Dominios/Sistema/HistorialController.php` (arma el resumen legible), `Support/ActivityLogger.php` (registra)
- Tabla: `activity_logs` (old/new values + `_detalles` = motivo)

### 9. Inicio por tareas
- Pantalla: `modulos/dashboard/Dashboard.jsx` → `TareasFrecuentes.jsx`
- Las pantallas de asistencia/calificaciones arrancan en `todos` salvo `?anio=&division=` por URL

### 10. Imprimibles (ventana nueva, con permiso por curso)
- Rutas web: `/horarios/planilla`, `/calificaciones/planilla`, `/print/alumnos`, `/print/asistencia`
  (todas con middleware `auth.token`; los iframes usan `?ticket=` de un solo uso
  que entrega `POST /api/print/ticket` — el `api_token` real nunca va en la URL)
- Vistas: `resources/views/horarios/planilla.blade.php`, `resources/views/calificaciones/planilla.blade.php`

### 11. Actualización automática de las pantallas
- API: `GET /api/datos/sello` → `{ "alumnos": 12, "calificaciones": 8, ... }` (devuelve todos los grupos; no toma parámetros)
- Backend: `Dominios/Sistema/SelloCambios.php` (clave `sello:grupo:<grupo>` en caché, se incrementa en cada `ActivityLogger::log`)
- Frontend: `compartido/hooks/useAvisoDeCambios.js` + `compartido/lib/dataStamp.js` → sondea cada 12 s y refresca solo si la pantalla está libre
- No hay botón ni cartel de novedades: si una pantalla nueva necesita datos al día, declara sus grupos en `useAvisoDeCambios(grupo, [grupos], actualizar)`

## Convenciones (para mantener el orden)
1. Un proceso = un dominio. Si tocás " Pasar lista", casi todo está en `asistencia/`.
2. Lo compartido vive en `compartido/` (frontend) y `Support/` (backend). No duplicar helpers.
3. Pantalla + sus piezas juntas: `modulos/<x>/` tiene la página, sus componentes y sus CSS.
4. Backend espeja frontend: `app/Dominios/<X>/` tiene controlador(es) + modelos del mismo proceso.
5. Nombres: español, mismo vocabulario de la pantalla (`recursando`, `preceptor`, `planilla`).
6. `_detalles` = motivo del cambio (lo pide el modal de confirmación y lo guarda el historial).
7. Los permisos se verifican en el backend (`AuthorizesScope`); el frontend solo oculta/muestra.
