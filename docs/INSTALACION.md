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

## 8. Sin Docker (avanzado)

- Backend: PHP 8.2 + Composer → `cd backend; composer install; php artisan migrate`.
- Frontend: Node 22 → `cd frontend; npm install; npm run dev`.
- El modo oficial y probado es con Docker.
