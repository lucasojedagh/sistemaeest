# Sistema ENRO — Escuela

Sistema de gestión escolar (alumnos, asistencia, calificaciones, cursos, profesores, usuarios).

## ¿Dónde está cada cosa?

```
Sistema-ENRO-main/
├── backend/            ← API en Laravel (PHP). Lógica + base de datos.
│   ├── app/Dominios/   ← Un proceso = una carpeta (Alumnos, Asistencia, ...)
│   ├── app/Support/    ← Ayudas compartidas (permisos, reglas)
│   ├── routes/api.php  ← TODAS las rutas de la API están acá
│   ├── database/migrations/ ← La estructura de la BD (fuente de verdad)
│   └── database/seeders/    ← Datos iniciales (usuario admin, etc.)
├── frontend/           ← Pantallas en React. Lo que ve el usuario.
│   └── src/
│       ├── modulos/    ← Una carpeta por pantalla (alumnos, asistencia, ...)
│       │                 y su CSS al lado del .jsx que lo usa
│       └── compartido/ ← Lo reusable (botones, helpers, CSS global)
├── archivos/           ← Plantillas de Excel que lee el backend
├── docker/             ← Cómo se arma cada contenedor (casi no se toca)
├── scripts/            ← Tareas listas para usar (ver abajo)
├── base-de-datos/      ← Esquema + respaldos viejos (solo lectura)
├── backups/            ← Acá caen los backups que genera el script
├── docs/               ← Documentación
│   ├── MAPA_SISTEMA.md ← Qué archivo hace qué (pantalla → API → tabla)
│   ├── INSTALACION.md  ← Guía paso a paso (Windows + Docker)
│   ├── DESPLEGUE-LINUX.md ← Cómo publicarlo en el server de la institución
│   └── reporte-cambios.pdf
└── docker-compose.yml  ← Qué servicios se levantan (MySQL, backend, frontend)
```

> Regla de oro: `frontend/src/modulos/<x>/` muestra la pantalla y
> `backend/app/Dominios/<X>/` hace el trabajo. Nombres espejados.
>
> Dos reglas más para encontrar rápido cualquier archivo:
> el CSS de una pantalla vive al lado de su `.jsx` (en `compartido/styles/`
> sólo están `variables.css` y `base.css`, que carga `index.css`), y los helpers
> compartidos están todos en `compartido/lib/`. La lista completa de rutas de la
> API está en `backend/routes/api.php`, y el mapa proceso→archivo en
> `docs/MAPA_SISTEMA.md`.

## Uso diario (3 pasos)

### En la máquina de desarrollo (Laragon, sin Docker)

Docker no hace falta acá: se corre el backend y el frontend por separado.

1. Backend: `cd backend && php artisan serve` → http://localhost:8000/api/ping
2. Frontend: `cd frontend && npm run dev` → http://localhost:5173
3. MySQL: el de Laragon (puerto 3306), con `backend/.env` apuntando a `127.0.0.1`.

> `php artisan serve` y `npm run dev` son servidores de **desarrollo**: atienden
> de a una petición y recompilan sobre la marcha. Sirven para trabajar, no para
> que entre toda la escuela. Para eso está el server de la institución (§abajo).

### En el server de la institución (Linux + Docker)

```bash
bash scripts/iniciar.sh      # construye, levanta, composer -o, migra y verifica
bash scripts/detener.sh      # apaga (los datos quedan en el volumen mysql_data)
```

El paso a paso completo —claves, puerto público, correo real, respaldos y
cron— está en [`docs/DESPLEGUE-LINUX.md`](docs/DESPLEGUE-LINUX.md).

### En Windows con Docker (si ese equipo tiene Docker Desktop)

1. Iniciar: `.\scripts\iniciar.ps1`
2. Abrir:
   - Pantallas: http://localhost:5173
   - API: http://localhost:8000/api/ping
   - Correos de prueba: http://localhost:8025 (solo desde el propio equipo)
3. Detener: `.\scripts\detener.ps1`

## Scripts disponibles (`scripts/`)

| Script | ¿Para qué? |
|---|---|
| `iniciar.ps1` | Windows + Docker: construye, levanta todo, instala dependencias y migra la BD |
| `iniciar.sh` | Linux + Docker: lo mismo, y además espera a que MySQL esté sano, optimiza el autoloader, enlaza `storage` y verifica `/api/ping` |
| `detener.ps1` / `detener.sh` | Apagan los contenedores (con `--volumenes` además **borra la base**: pide confirmación escrita) |
| `crear-base-de-datos-desde-cero.ps1` | BORRA y recrea la BD `sistema_escuela` + seeders (guarda backup en `backups/`) |
| `crear-base-de-datos-desde-cero.sh` | Lo mismo, para Linux/Git Bash |

## Base de datos

- Motor: MySQL 8. Base: `sistema_escuela`. Puerto: `3307`, y en el compose queda
  escuchando **solo en `127.0.0.1`** para que la red del colegio no pueda entrar
  directo a la base.
- Usuario/clave: `root` con la clave que define `ENRO_DB_PASSWORD` en el `.env`
  del compose (si no se pone, el default de desarrollo es `root`).
- La estructura real la definen `backend/database/migrations/`.
- `base-de-datos/schema.sql` es una foto de referencia. Los `.sql` viejos quedaron
  archivados en `base-de-datos/respaldos-antiguos/` (no se usan para levantar el sistema).

## Si algo falla

1. `docker compose ps` — ¿están los 5 contenedores en `Up`?
2. `docker compose logs -f backend` — errores del API.
3. `docker compose logs -f frontend` — errores de pantallas.
4. Detalle largo en `docs/INSTALACION.md`.
