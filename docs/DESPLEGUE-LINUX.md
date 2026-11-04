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
Arranca con valores que ya funcionan; en el server hay que revisar tres:

- `APP_KEY`: la que está en el repo sirve para arrancar, pero es pública. Generar
  una propia (§4) y **no perderla**: con otra key se leen mal las cookies y los
  datos cifrados viejos.
- `FRONTEND_URL`: tiene que ser el origen real con el que entran los preceptores
  (`http://192.168.1.20` o `http://sistema.escuela.edu.ar`). **Este valor arma el
  link de "recuperar contraseña"** que sale en el correo: si queda en `localhost`,
  el mail no abre desde ningún otro equipo.
- `MAIL_*`: por defecto apuntan a `mailpit`, que es una bandeja de prueba local.
  Para correo real del colegio, poner el SMTP de la institución y apagar
  mailpit en el arranque (§5).

`APP_ENV=production` y `APP_DEBUG=false` ya vienen puestos. Con `APP_DEBUG=true`
cualquier error 500 mostraría en pantalla la traza completa, con las consultas
SQL incluidas, a cualquier preceptor que entre. Se deja en `true` únicamente para
diagnosticar, y se reinicia el contenedor: `docker compose restart backend`.

**b) `.env` al lado del `docker-compose.yml`** — crea ahí mismo el archivo
(opcional, son los valores que lee Compose):

```bash
cat > .env <<'EOF'
ENRO_HTTP_PORT=80
ENRO_DB_PASSWORD=una-clave-larga-y-privada
EOF
```

`ENRO_HTTP_PORT` define dónde entra la escuela (`80` para escribir solo la IP,
sin `:8000`). `ENRO_DB_PASSWORD` es la clave de root de MySQL: si no se pone,
quedará `root`, que es el default del desarrollo y no sirve en producción.

## 4. Levantar el sistema

```bash
bash scripts/iniciar.sh
```

Hace, en orden: construir imágenes → levantar contenedores → esperar a que MySQL
esté sano → `composer install --optimize-autoloader` → `migraciones --force` →
`storage:link` → verificar `/api/ping`.

Después, generar la clave propia de la app y crear el usuario admin:

```bash
cp backend/.env.docker backend/.env         # artisan edita el .env correcto
docker compose exec backend php artisan key:generate --force
docker compose exec backend php artisan migrate --seed --force
docker compose restart backend              # para que tome la APP_KEY nueva
```

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

**b) Desde el server, por cron** — volcado nocturno dentro del contenedor de
MySQL y retención de 14 días:

```cron
0 3 * * * docker compose -f /opt/escuela/Sistema-ENRO/docker-compose.yml exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction sistema_escuela' | gzip > /var/backups/enro/bd_$(date +\%Y-\%m-\%d).sql.gz
15 3 * * * find /var/backups/enro -name 'bd_*.sql.gz' -mtime +14 -delete
```

`--single-transaction` es lo que evita que el dump bloquee las tablas mientras
los preceptores están trabajando. Copiar `/var/backups/enro` fuera del server
(otro disco o la nube) al menos una vez por semana: el respaldo que vive junto a
la base no sirve si se muere el disco.

**c) Cron de Laravel** — `backups:prune` (borra respaldos viejos del sistema,
conserva los 3 más nuevos) corre solo si existe la entrada de `schedule:run`:

```cron
* * * * * cd /opt/escuela/Sistema-ENRO && docker compose exec -T backend php artisan schedule:run >> /var/log/enro-schedule.log 2>&1
```

Y además hay que respaldar `backend/storage/` (documentos y fotos subidas, que
no viven en MySQL sino en disco) y el volumen `mysql_data`.

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
| "No application encryption key" | `backend/.env` dentro del contenedor | correr `key:generate` (§4) y `docker compose restart backend` |
| No llega el correo de recupero | `docker compose logs backend mailpit` | `FRONTEND_URL` mal puesto (§3) o SMTP sin credenciales |
| El login funciona lento o da parpadeos | `docker compose ps` | revisar que no haya un `npm run dev` peleando el puerto |
| "Access denied for user root" | `ENRO_DB_PASSWORD` | la clave del compose y la del volumen tienen que coincidir; si el volumen ya existía con otra, hay que cambiarla dentro de MySQL |
| Puerto ya en uso | `ss -lntp \| grep <puerto>` | liberar el puerto o cambiar `ENRO_HTTP_PORT` |
| "Permission denied" escribiendo en `storage/` o `bootstrap/cache/` | `docker compose logs backend` | PHP-FPM corre como `www-data` (uid **82** adentro de la imagen). En el host: `sudo chown -R 82:82 backend/storage backend/bootstrap/cache` |

Para entrar a la base desde el propio server (diagnóstico, nunca para editar
datos de alumnos a mano):

```bash
# La clave se lee adentro del contenedor (MYSQL_ROOT_PASSWORD), no del shell del host.
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" sistema_escuela'
```
