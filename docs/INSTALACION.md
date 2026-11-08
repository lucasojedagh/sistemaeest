# Instalación paso a paso (Windows)

## 1. Requisitos

- Docker Desktop instalado y en ejecución.
- PowerShell (ya viene con Windows).
- Puertos libres: `5173` (pantallas), `8000` (API), `3307` (MySQL), `8025` (correos).

## 2. Primera vez

Desde la raíz del repo:

```powershell
.\scripts\iniciar.ps1
```

Eso hace, en orden: construir imágenes → levantar contenedores →
instalar dependencias PHP (si faltan) → correr migraciones.

## 3. Verificar que anda

- http://localhost:5173 → pantallas (frontend).
- http://localhost:8000/api/ping → debe devolver `{"ok":true}` (API).
- http://localhost:8025 → bandeja de correos de prueba.

## 4. Crear usuario admin y datos base

Los seeders corren en cadena desde `DatabaseSeeder`: usuario admin, parámetros
de divisiones, cursos y — siempre — los datos de demostración de `AlumnoSeeder`
y `DemoDataSeeder` (materias, alumnos de muestra, horarios y notas de ejemplo).
No hay una variable que saltee esa parte. Si partiste de BD vacía:

```powershell
docker compose exec backend php artisan db:seed --force
```

> Esta es la guía de **desarrollo**. En el server de la institución ese comando
> no va: deja mezclados los alumnos de muestra con los reales. Ahí se siembran
> solamente `AdminUserSeeder` y `ParametroDivisionesSeeder`, y los alumnos
> entran por la importación de la planilla — ver `DESPLEGUE-LINUX.md` §4.
>
> Y si los datos ya existen en otra máquina (la notebook donde se está trabajando
> hoy), no se importa ni se siembra: se migra el paquete completo con
> `scripts/exportar-migracion.sh` y `scripts/restaurar-migracion.sh` — ver
> `DESPLEGUE-LINUX.md` §14.

La clave inicial sale de `ADMIN_PASSWORD` (ver `backend/.env.example`).
Si está vacía, el seeder genera una aleatoria y la muestra **una sola vez**
en la salida del comando: anotarla. Para fijarla vos, definí
`ADMIN_EMAIL` y `ADMIN_PASSWORD` en `backend/.env` antes de sembrar.

**No vuelvas a correr el seeder sobre una base con datos reales**: los datos de
muestra usan DNI fijos (40000000 y siguientes) y el seed anterior choca contra el
`dni UNIQUE`; además deja filas de ejemplo mezcladas con las de la escuela. En
una escuela real se siembra una vez para probar, y después se cargan los datos
propios limpiando los de muestra.

## 5. Empezar de cero (cuidado: BORRA todo)

```powershell
.\scripts\crear-base-de-datos-desde-cero.ps1
```

El script hace `DROP DATABASE` y `CREATE DATABASE` sobre `sistema_escuela`, corre
migraciones y seeders, y **no pide confirmación**: se ejecuta con apretar Enter.
Antes intenta un `mysqldump` en `backups/`, pero si el backup falla el script lo
avisa en amarillo y **igual sigue adelante**. Por eso:

- Corerlo solo contra la base de desarrollo local.
- Si el backup es necesario, hacelo vos antes (pantalla de Backups o `mysqldump`)
  y verificá que el archivo tenga tamaño y contenido.
- Para confirmar en qué base estás parado: `php artisan tinker` y
  `DB::connection()->getDatabaseName();`.

### Sobre `migrate:fresh`, `migrate:refresh`, `migrate:reset` y `db:wipe`

No existe una variable `ALLOW_DB_RESET` ni un guard que los bloquee: artisan
ejecuta esos comandos siempre, y con `--force` ni siquiera preguntan. La
protección es la revisión previa (base conectada + backup verificado). Para el
día a día usá `php artisan migrate`, que aplica solo las migraciones nuevas.

## 6. Apagar

```powershell
.\scripts\detener.ps1
```

## 7. Problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `5173` no responde | frontend aún instalando `npm install` | esperar 1-2 min, ver `docker compose logs -f frontend` |
| `8000` da error de BD | MySQL aún arrancando | esperar al healthcheck, ver `docker compose logs -f mysql` |
| Cambios en pantalla no se ven | caché del navegador | recargar con Ctrl+F5 |
| Puerto ocupado | otro programa usa el puerto | liberar el puerto o detener el otro contenedor |
| `npm: no se reconoce...` (Windows) | el Node de Laragon no está en el PATH | `call C:\laragon\bin\nodejs\node-v22\nodevars.bat` antes de `npm` |
| `Missing script: "build"` | `npm run build` corrió en una carpeta sin ese script | correrlo en la raíz del repo o en `frontend` (ver §8) |

## 8. Sin Docker (avanzado)

- Backend: PHP 8.2 + Composer → `cd backend; composer install; php artisan migrate`.
- Frontend: Node 22 → `cd frontend; npm install; npm run dev`.
- Build de producción en Windows (Laragon, sin Docker). El `npm` de Laragon no queda en el
  PATH de una terminal común, así que hay que activarlo primero. Desde `cmd`:

  ```bat
  call C:\laragon\bin\nodejs\node-v22\nodevars.bat
  cd C:\laragon\www\Sistema-ENRO-main
  npm run build
  ```

  `npm run build` funciona tanto en la raíz del repo —donde el `package.json` lo reenvía a
  `frontend`— como dentro de `frontend`. Escribe en `frontend/dist`, que es lo que sirve el
  servidor; el `nodevars.bat` también hace falta para `npm run lint` y `npm install`.
- El **manual de usuario** viaja dentro del `dist`: el build copia el PDF de
  `docs/` a `dist/manuales/` y genera su `indice.json`. Si al server solo se le
  sube `dist`, el modal "Manuales" lo descarga de ahí; si además está `docs/`,
  manda la API (`/api/manuales`).
- El **manual del programador** NO se copia al `dist`: es documentación técnica y
  un archivo dentro de `dist` es una URL pública. Se sirve solo por la API, que
  es donde se filtra el permiso `manuales.programador`; para que aparezca, la
  carpeta `docs/` tiene que estar en el server junto al backend.
- El modo oficial y probado es con Docker.
