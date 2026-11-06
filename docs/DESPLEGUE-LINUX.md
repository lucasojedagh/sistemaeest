# Publicación en servidor Linux (Docker)

Guía para dejar el Sistema ENRO corriendo en el server de la institución, con
1200+ alumnos y varios preceptores entrando a la vez. El camino probado es el
`docker-compose.yml` de la raíz: en Linux Docker corre nativo (no hace falta
WSL como en Windows) y ese compose ya trae todo lo que el sistema necesita.

Los contenedores son cinco: `mysql`, `backend` (Laravel + PHP-FPM con OPcache),
`frontend` (el build de React servido por nginx), `nginx` (el único punto de
entrada) y `mailpit` (bandeja de correo de prueba).

---

## 1. Requisitos del server

- Ubuntu/Debian (o cualquier distro) con **Docker Engine** y **Docker Compose v2**.
  Se comprueba con `docker compose version`; si no existe, instalarlo antes.
- Puertos libres: el público (por defecto `8000`, ver §3) y ninguno más abierto
  a la red: MySQL y la bandeja de correo quedan escuchando solo en `127.0.0.1`.
- 40 GB de disco como mínimo: la imagen de MySQL, el `vendor/` de Composer y los
  respaldos que genera el propio sistema en `backend/storage/app/backups`.
- El usuario que despliega tiene que estar en el grupo `docker`
  (`sudo usermod -aG docker $USER` y volver a entrar). Si no, anteponer `sudo`
  a cada comando `docker`.

## 2. Bajar el código

```bash
sudo mkdir -p /opt/escuela && sudo chown -R $USER /opt/escuela
cd /opt/escuela
git clone <repo> Sistema-ENRO      # o copiar la carpeta del proyecto
cd Sistema-ENRO
```

Copiar el proyecto como carpeta funciona, pero el `git pull` es lo que hace
reversible un cambio: poder volver a la versión anterior en un minuto.

## 3. Configurar una sola vez

Dos archivos, y cada uno se usa para una cosa distinta:

**a) `backend/.env.docker`** — es el `env_file` del contenedor de Laravel.
En el server hay que revisar tres cosas, y `scripts/iniciar.sh` se niega a
levantar nada si alguna sigue con el valor del repo (bloque `[0/5]`):

- `APP_KEY`: **viene vacía a propósito**. La clave que estaba en el archivo se
  subió al repositorio público, así que no es secreta: cualquiera con el repo
  puede descifrar cookies y campos cifrados. Se genera la propia y se **pega en
  esta misma línea** (§4). Anotarla fuera del server: con otra key se leen mal
  los datos cifrados viejos, y no hay forma de recuperarlos.
- `APP_URL` y `FRONTEND_URL`: tienen que ser el origen real con el que entran
  los preceptores (`http://192.168.1.20` o `http://sistema.escuela.edu.ar`).
  **Este valor arma el link de "recuperar contraseña"** que sale en el correo:
  si queda en `localhost`, el mail no abre desde ningún otro equipo y el API no
  sabe a qué host responder.
- `MAIL_*`: por defecto apuntan a `mailpit`, que es una bandeja de prueba local.
  Para correo real del colegio, poner el SMTP de la institución y apagar
  mailpit en el arranque (§5). El preflight lo avisa pero no aborta: el sistema
  funciona igual, solo que los correos no salen.

`APP_ENV=production` y `APP_DEBUG=false` ya vienen puestos. Con `APP_DEBUG=true`
cualquier error 500 mostraría en pantalla la traza completa, con las consultas
SQL incluidas, a cualquier preceptor que entre (el preflight también lo frena).
Se deja en `true` únicamente para diagnosticar, y se reinicia el contenedor:
`docker compose restart backend`.

**b) `.env` al lado del `docker-compose.yml`** — crea ahí mismo el archivo
(opcional, son los valores que lee Compose):

```bash
cat > .env <<'EOF'
ENRO_HTTP_PORT=80
ENRO_DB_PASSWORD=una-clave-larga-y-privada
EOF
```

`ENRO_HTTP_PORT` define dónde entra la escuela (`80` para escribir solo la IP,
sin `:8000`); `iniciar.sh` lee este archivo, así que el puerto que muestra al
final y la prueba de `/api/ping` son los reales, no los del default.
`ENRO_DB_PASSWORD` es la clave de root de MySQL: sin ella quedaría `root`, que
es el default del desarrollo. **En el server es obligatorio** — es la clave de
la base con los 1200 alumnos, y el compose la usa también para el backend.

No hace falta un usuario de MySQL aparte: el puerto 3306 del contenedor no se
publica (solo `127.0.0.1:3307` para herramientas del propio server), así que el
`root` solo se usa desde la red interna del compose. Si igual se quiere un
usuario propio, se crea con `CREATE USER` y se cambian `DB_USERNAME` y el
`MYSQL_ROOT_PASSWORD` del servicio, sin tocar la key de la app.

## 4. Levantar el sistema

Primero la clave de la app, **antes** de correr `iniciar.sh` (el preflight no
deja levantar sin ella):

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

Esa salida se pega en la línea `APP_KEY=` de `backend/.env.docker` (queda
`APP_KEY=base64:xxxx...`) y se anota en un papel aparte. Se puede generar
también con `docker compose run --rm backend php artisan key:generate --show`,
pero `openssl` no necesita tener la imagen construida.

> **Por qué se edita `.env.docker` y no se corre `key:generate` a secas:** el
> `key:generate` del contenedor escribe en `backend/.env`, y el `APP_KEY` que
> pasa el `env_file` de Compose **le gana** — Laravel carga el `.env` con un
> repositorio *immutable* que no pisa variables que ya existen en el entorno,
> aunque estén vacías. Medido con el propio `phpdotenv` del proyecto:
> `APP_KEY=` vacío en `.env.docker` → el valor del `.env` nunca se aplica, y el
> sistema queda con "No application encryption key" aunque el archivo esté lleno.

Después:

```bash
bash scripts/iniciar.sh
```

Hace, en orden, y aborta antes de tocar Docker si la configuración está incompleta:

| Paso | Qué corre | Si falla |
|---|---|---|
| `[0/5]` | preflight: `APP_KEY`, `APP_DEBUG`, `APP_URL`/`FRONTEND_URL`, `ENRO_DB_PASSWORD`, mailpit, los dos cron | lista los errores y no levanta nada |
| `[1/5]` | `docker compose build` | `docker compose build --no-cache backend` |
| `[2/5]` | `up -d` + espera al healthcheck de MySQL (60 × 2 s) | `docker compose logs mysql` |
| `[3/5]` | `composer install --optimize-autoloader` | falta `vendor/`, o sin red |
| `[4/5]` | `migrate --force`, `esquema:verificar`, `storage:link`, `chown` de `storage/` y `bootstrap/cache` | ver §10 |
| `[5/5]` | `curl /api/ping` | `docker compose logs backend nginx` |

En una base **vacía**, después de que levante, crear lo mínimo que necesita una
escuela real:

```bash
docker compose exec backend php artisan db:seed --class=AdminUserSeeder --force
docker compose exec backend php artisan db:seed --class=ParametroDivisionesSeeder --force
```

El primero imprime la clave del admin **una sola vez** (o usa `ADMIN_PASSWORD`
si lo definís); anotarla. Los cursos y los alumnos reales entran por la
importación de la planilla, no por seed.

> **No correr `php artisan migrate --seed` en el server.** `DatabaseSeeder`
> encadena `AlumnoSeeder`, `DemoDataSeeder` y `ProfesorSeeder`, que meten los
> alumnos, materias y profesores de demostración (los mismos 57 de prueba que
> se borraron de la base de trabajo). Es el comando del desarrollo local; en
> producción solo los dos seeders de arriba.

## 5. Verificar que está servido (no solo levantado)

```bash
docker compose ps                            # los 5 servicios en Up (mysql healthy)
curl -s http://127.0.0.1:80/api/ping         # {"ok":true}
```

Desde otro equipo de la red del colegio: abrir `http://<IP-del-server>/`, loguear
y mirar el padrón de un curso con muchos alumnos. Después entrar al **Inicio
(Dashboard)**, que es donde están los botones de respaldo, y probar "Descargar
backup SQL".

Si el server tiene firewall (`ufw`), abrir solo el puerto público:

```bash
sudo ufw allow 80/tcp        # o 8000/tcp si se dejó el default
```

Para correo real y apagar la bandeja de prueba:

```bash
docker compose up -d --scale mailpit=0
```

## 6. Actualizar el sistema después

- Cambió el **frontend** (o `VITE_API_BASE`): hay que **recompilar la imagen**,
  porque el bundle se quema adentro:
  `docker compose build frontend && docker compose up -d frontend`
- Cambió el **backend**: con el bind mount actual el código se toma del disco,
  alcanza con `docker compose up -d` (o `docker compose restart backend`) y
  correr `migrate --force` si el cambio trajo migraciones.
- Cambió `docker-compose.yml`: `docker compose up -d` de nuevo.

Antes de actualizar: respaldo (§7). El sistema guarda el historial de actividad
y no tiene "deshacer" a nivel de base de datos.

## 7. Respaldos (esto no es opcional con 1200 alumnos)

Dos copias, en lugares distintos:

**a) Dentro del sistema** — pantalla de respaldos: "Descargar `.sql`" genera el
volcado con PHP puro, sin depender de `mysqldump`, y ese archivo es el que se
puede volver a importar desde la misma pantalla. El botón de enviar por correo
intenta `mysqldump` primero y, como la imagen de PHP-FPM no lo trae, termina
mandando un `.zip` en JSON: sirve como copia de seguridad de los datos, **pero la
pantalla de importación solo lee `.sql`/`.txt`**. Es decir: para poder restaurar,
bajar el `.sql`.

Si se quieren `.sql` por correo, instalar `mysql-client` en la imagen de backend
(fijándose de que la versión coincida con MySQL 8; el cliente de MariaDB no
soporta el método de autenticación default de MySQL 8 y falla en silencio).

**b) Desde el server, por cron** — un comando instala los dos agendamientos que
el sistema necesita (volcado nocturno de la base + planificador de Laravel):

```bash
bash scripts/instalar-cron.sh
ENRO_BACKUP_DIR=/otro/disco bash scripts/instalar-cron.sh    # si los respaldos van en otro lado
```

Agrega al crontab del usuario actual, y reemplaza lo que ya haya puesto (las
líneas van marcadas con `ENRO_RESPALDO=1` / `ENRO_SCHEDULE=1`, así es
idempotente y no toca el cron de ninguna otra app):

| Cuándo | Qué corre | Para qué |
|---|---|---|
| 03:00 | `scripts/respaldar-bd.sh` | `mysqldump --single-transaction` dentro del contenedor de MySQL, gzip, verificación y retención de 14 días |
| cada minuto | `php artisan schedule:run` | es lo que hace correr `backups:prune` (borra los `.sql` de la pantalla de Respaldos más viejos de 30 días); sin esta línea esa carpeta crece para siempre |

Las corridas escriben en `/var/backups/enro/cron.log` y `schedule.log` (no a
`/var/log`, que pide `sudo`). El script de respaldo también se puede correr a
mano antes de un cambio riesgoso:

```bash
ENRO_BACKUP_DIR=/var/backups/enro bash scripts/respaldar-bd.sh
```

`--single-transaction` es lo que evita que el dump bloquee las tablas mientras
los preceptores están trabajando.

> **Por qué el volcado vive en un script y no en la línea del crontab** (como
> estaba documentado antes): la línea larga terminaba en
> `... > bd_$(date...).sql.gz >> cron.log 2>&1` y la segunda redirección
> **reemplaza** la primera, así que el `.sql.gz` quedaba de 0 bytes y el
> binario gzip iba a parar al log. En cron no hay nadie mirando: el respaldo
> "existía" todos los días y no servía ninguno. El script además verifica —
> rechaza el volcado vacío y el que no cierra con `-- Dump completed`, que es
> lo que distingue un dump truncado de uno bueno (probado contra un
> `mysqldump` real de esta base, 906 KB).

Copiar `/var/backups/enro` fuera del server (otro disco o la nube) al menos una
vez por semana: el respaldo que vive junto a la base no sirve si se muere el
disco.

**c) Qué respaldar además** — `backend/storage/` (documentos y fotos subidas,
que no viven en MySQL sino en disco) y el volumen `mysql_data`.

## 8. Rendimiento: qué está activado y qué falta

**Ya activado en la imagen de Docker** (`docker/backend/`):

- **OPcache** con 256 MB, `validate_timestamps=1` y `revalidate_freq=2`. Se deja
  la validación encendida a propósito: el código está montado desde el host y,
  con `validate_timestamps=0`, PHP seguiría ejecutando la versión vieja después
  de editar un archivo sin avisar. Para un server con el código congelado se
  puede pasar a `0` en `docker/backend/opcache.ini` y `docker compose build
  backend`, pero entonces cada deploy tiene que reconstruir o reiniciar el
  contenedor.
- **nginx** con gzip (JSON de la API y JS/CSS), `Cache-Control` de un año con
  `immutable` para los assets hasheados y `no-cache` para `index.html`, y un
  único origen: el navegador de la escuela habla con un solo host, sin CORS.
- **PHP-FPM** con su pool propio (`docker/backend/php-fpm-pool.conf`), que es lo
  que permite atender varias peticiones en paralelo. `php artisan serve`, en
  cambio, atiende de a una: con varios preceptores a la vez se forma cola. Por
  eso el server va con Docker, no con `artisan serve`.

**Por qué NO se corre `php artisan config:cache`** en este armado:
`./backend` está montado por volumen contra el repo del host, así que el archivo
cacheado (`bootstrap/cache/config.php`) se escribe en el mismo disco que ve el
desarrollo. Ese archivo **congela** los valores de entorno del momento del cacheo
(`DB_HOST=mysql`, entre otros) y, desde entonces, un `php artisan serve` en la
máquina de desarrollo intenta conectar a un host `mysql` que no existe. Para
activarlo hay que separar los códigos: copiar `backend/` dentro de la imagen en
lugar de montarlo, y ahí sí `config:cache`, `route:cache` y
`composer install --no-dev --optimize-autoloader` en el `Dockerfile`.

**El cuello de botella real no era el arranque de Laravel sino las consultas**:
el padrón completo, el dashboard, la asistencia y la planilla de notas estaban
cargando tablas enteras o haciendo una consulta por fila. Se resolvieron por
agregados SQL y escrituras en lote (tareas #26 a #31 del registro de cambios).

## 9. Dónde queda cada cosa

| Dato | Ubicación |
|---|---|
| Base de datos | volumen Docker `mysql_data` (no está en el repo) |
| Documentos y fotos subidas | `backend/storage/app/public` |
| Respaldos del sistema | `backend/storage/app/backups` |
| Logs de Laravel | `backend/storage/logs/laravel-*.log` (`LOG_CHANNEL=daily`) |
| Configuración secreta | `backend/.env.docker` y `backend/.env` |

Ninguno de esos archivos va al repositorio. Si se muda de server, lo que se lleva
es: el código, un `.sql` restaurable y la carpeta `storage/`.

## 10. Si algo falla

| Síntoma | Mirar | Solución |
|---|---|---|
| Página en blanco o 502 | `docker compose logs nginx backend` | el backend no levantó: ver el log de PHP-FPM |
| 500 con página genérica | `docker compose logs backend` o `backend/storage/logs` | con `APP_DEBUG=false` el detalle solo se ve en el log |
| No llega el correo de recupero | `docker compose logs backend mailpit` | `FRONTEND_URL` mal puesto (§3) o SMTP sin credenciales |
| El login funciona lento o da parpadeos | `docker compose ps` | revisar que no haya un `npm run dev` peleando el puerto |
| "Access denied for user root" | `ENRO_DB_PASSWORD` | la clave del compose y la del volumen tienen que coincidir; si el volumen ya existía con otra, hay que cambiarla dentro de MySQL |
| Puerto ya en uso | `ss -lntp \| grep <puerto>` | liberar el puerto o cambiar `ENRO_HTTP_PORT` |
| "Permission denied" escribiendo en `storage/` o `bootstrap/cache/` | `docker compose logs backend` | PHP-FPM corre como `www-data` (uid **82** adentro de la imagen). En el host: `sudo chown -R 82:82 backend/storage backend/bootstrap/cache` |
| `iniciar.sh` aborta en `[0/5]` sin tocar nada | los `ERROR:` que imprime | es el preflight: falta `APP_KEY`, o `APP_URL`/`FRONTEND_URL` en `localhost`, o `ENRO_DB_PASSWORD` no puesto (§3 y §4) |
| "No application encryption key" con `APP_KEY` llena en `backend/.env` | `docker compose exec backend sh -c 'echo "$APP_KEY"'` | el `env_file` define `APP_KEY=` vacía y **le gana** al `.env`: la clave va en `backend/.env.docker`, no en `backend/.env` (§4) |
| `esquema:verificar` imprime `FALTA`/`SOBRA` | la lista que sale en el terminal | alguien corrió un `ALTER` a mano: convertirlo en migración (§12) |
| Los respaldos por cron no aparecen o salen de 0 bytes | `${ENRO_BACKUP_DIR:-/var/backups/enro}/cron.log` | el usuario del cron no está en el grupo `docker`, o el agendamiento nunca se instaló: `bash scripts/instalar-cron.sh` (§7b) |
| `backend/storage/app/backups` no se limpia nunca | `crontab -l \| grep schedule` | falta la línea de `schedule:run`: sin ella `backups:prune` no corre (§7b) |

Para entrar a la base desde el propio server (diagnóstico, nunca para editar
datos de alumnos a mano):

```bash
# La clave se lee adentro del contenedor (MYSQL_ROOT_PASSWORD), no del shell del host.
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" sistema_escuela'
```

## 11. Sin Docker: `npm run build` y el `dist` en el server

Es el camino corto cuando el colegio ya tiene un Apache/nginx con PHP levantado:
se compila el frontend acá y se copia el `dist` al server. Tres reglas, y las
tres se prueban juntas con el simulador de la sección final:

**a) El `dist` va en la RAÍZ del sitio**, no en una subcarpeta. Los assets del
build se referencian con `/assets/...` (ruta absoluta desde el origen); si el
SPA queda en `http://server/sistema/`, el navegador pide `/assets/...` y no
existe: pantalla en blanco. Para publicar en una subcarpeta hay que recompilar
con `base` en `vite.config.js`, no copiar el mismo `dist`.

**b) El server tiene que enrutar al backend lo que NO es del SPA.** Con `VITE_API_BASE`
sin definir, un build de producción usa `/api`: mismo origen que la página. El
servidor web tiene que mandar a Laravel `/api/`, `/print/`, `/storage/` y los
imprimibles exactos (`/calificaciones/planilla`, `/calificaciones/planilla/excel`,
`/calificaciones/planilla/guardar`, `/horarios/planilla`); todo lo demás se sirve
desde el `dist` con fallback a `index.html` (si no, recargar `/alumnos` da 404).
Si la API vive en OTRO host, quemar la URL en el build:
`VITE_API_BASE=https://api.escuela.edu.ar/api npm run build` y abrir el CORS de ese
backend (`backend/config/cors.php`).

nginx, ya sin contenedores:

```nginx
root /var/www/enro/dist;                 # el dist copiado acá
index index.html;

location ^~ /assets/ { add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }
location /api/       { try_files $uri $uri/ /index.php?$query_string; }         # Laravel
location /print/     { try_files $uri $uri/ /index.php?$query_string; }
location /storage/   { try_files $uri $uri/ /index.php?$query_string; }
location = /calificaciones/planilla         { try_files $uri $uri/ /index.php?$query_string; }
location = /calificaciones/planilla/excel   { try_files $uri $uri/ /index.php?$query_string; }
location = /calificaciones/planilla/guardar { try_files $uri $uri/ /index.php?$query_string; }
location = /horarios/planilla               { try_files $uri $uri/ /index.php?$query_string; }

location / { try_files $uri $uri/ /index.html; }   # fallback SPA
```

**c) `APP_DEBUG=false` en el backend del server.** Con `true`, cualquier error
devuelve la traza completa con SQL y rutas de archivo — y esa traza la ve el
preceptor que abrió el link. Ver §3.

Probar el `dist` tal cual antes de llevarlo (sirve el build con el mismo
encaminado que va a tener en el server, usando el `php artisan serve` local como
backend):

```bash
cd frontend && npm run build
node scripts/servidor-publicacion.js 4173      # desde la raíz del proyecto
# abrir http://localhost:4173, loguear y recorrer los módulos
```

Si algo falla ahí, falla idéntico en el colegio; si anda acá, anda allá.

## 12. El esquema: todo cambio va en una migración

`base-de-datos/schema.sql` es una **foto**, no la definición de la base. La foto
se actualiza a mano y se pone linda, pero el server se construye con las
migraciones de `backend/database/migrations/`. Cuando algo se cambia con un
`ALTER` directo (el clásico "lo desbloqueo ahora y la migración la hago
después"), el server nuevo queda distinto del que funciona, y nadie se entera
hasta que un insert de la escuela real choca contra un índice que no debería
estar ahí.

Pasó dos veces y las dos se arreglaron en `2026_11_05_000007_especialidad_de_curso_y_materias_unique.php`:

- `cursos.especialidad` existía en la base de trabajo y en `schema.sql`, pero
  ninguna migración la creaba: un server nuevo no tenía la columna y
  `HorarioController` (que la lee para armar la planilla) rompía.
- El `UNIQUE` de `materias` estaba en `(anio, nombre, tipo)` mientras la base de
  trabajo tenía `(anio, nombre, tipo, coalesce(especialidad,''))`. Con el viejo,
  la misma materia dictada en dos especialidades del mismo año daba
  `ERROR 1062 Duplicate entry` al cargar el plan de estudios por planilla. El
  índice nuevo es **funcional**: `NULL` y `''` cuentan igual, así que una materia
  sin especialidad cargada dos veces sigue siendo un duplicado real y frena.

Por qué no lo agarraron las pruebas: `backend/phpunit.xml` corre sobre
`sqlite :memory:` y **ninguna migración se ejecuta** (todas son SQL de MySQL).
El `--seed` de desarrollo tampoco las corre, porque la base local ya estaba
armada. O sea: el único mecanismo que detecta el drift tiene que ser explícito.

```bash
php artisan esquema:verificar          # o dentro del contenedor
docker compose exec backend php artisan esquema:verificar
```

Crea una base auxiliar `<su-base>_esquema_check`, le aplica **todas** las
migraciones desde cero, saca la huella de columnas e índices de las dos bases y
las compara. Imprime `FALTA ...` (está en la base de trabajo y ninguna migración
lo crea) o `SOBRA ...` (lo crean las migraciones y la base no lo tiene) y
devuelve código de salida `1` si hay cualquier diferencia; la base auxiliar se
borra sola (`--dejar` la conserva para revisar a mano). Necesita permisos de
`CREATE/DROP DATABASE`, que el `root` del compose tiene.

`scripts/iniciar.sh` lo corre en el paso `[4/5]` después de `migrate`, así que
el drift se ve en cada despliegue. No aborta el arranque: el sistema funciona
igual, pero la diferencia hay que convertir en migración antes del próximo
`git pull`.

Regla para escribir la migración que falta: idempotente, con los guardas contra
`information_schema` (mirar `2026_11_05_000005_quitar_indices_redundantes.php` y
la `000007`), con `down()` que revierta de verdad, y sin tocar nunca un índice
`UNIQUE` sin decir por qué. Después, regenerar `base-de-datos/schema.sql` desde
una base migrada desde cero — no desde la del colegio — para que la foto vuelva
a representar al código.

## 13. Checklist de entrega (lo que se revisa antes de decir "está publicado")

1. `bash scripts/iniciar.sh` pasa el `[0/5]` sin errores y termina con
   `API respondiendo OK`.
2. `docker compose exec backend php artisan esquema:verificar` → `Esquema idéntico`.
3. Desde **otro** equipo de la red del colegio: abrir `http://<IP>/`, loguear,
   recorrer el padrón de un curso grande y el Inicio.
4. Recuperar contraseña: pedir el mail desde una cuenta real y abrir el link
   desde el navegador de otro equipo (prueba `FRONTEND_URL` y el SMTP juntos).
5. `bash scripts/instalar-cron.sh` corrida, y el `respaldar-bd.sh` a mano dejó un
   `.sql.gz` que pasa `gzip -t` y termina en `-- Dump completed`.
6. Restaurar ese `.sql.gz` en una base auxiliar y contar los alumnos: un respaldo
   que nunca se restauró no es un respaldo.
