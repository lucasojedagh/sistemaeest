#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/iniciar.sh
# Propósito: levantar el Sistema ENRO en un servidor Linux (el camino oficial de
#            publicación). Es el equivalente de scripts/iniciar.ps1 para Docker
#            nativo de Linux: imágenes -> contenedores -> composer -> migraciones.
# Uso:  bash scripts/iniciar.sh        (funciona desde cualquier directorio)
#           Se invoca con `bash` y no con ./ a propósito: si el repo llegó al
#           server en un zip o una copia, el permiso de ejecución se pierde.
# Requiere: docker + docker compose v2 (usa `docker compose`, no `docker-compose`).
#           Si el usuario no está en el grupo "docker", anteponer sudo.
# Config del server (se editan UNA vez, no en cada arranque):
#   - backend/.env.docker  -> FRONTEND_URL, APP_KEY, MAIL_* (ver docs/DESPLEGUE-LINUX.md)
#   - .env al lado de docker-compose.yml -> ENRO_HTTP_PORT, ENRO_DB_PASSWORD
# El bloque [0/5] revisa esa config y aborta antes de tocar Docker si sigue con
# los valores del repo (APP_KEY vacía, localhost, root), porque levantar el stack
# así da un sistema "en marcha" que en realidad no funciona para la escuela.
# ==============================================================================

set -euo pipefail

# El script se mueve a la raíz del repo (donde está docker-compose.yml).
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Lee una clave de un archivo env sin hacer `source`: los env traen comillas e
# interpolaciones, y ejecutarlas correría comandos que están en el archivo.
valor_env() {
    local archivo="$1" clave="$2" valor
    valor="$(grep -m1 -E "^[[:space:]]*${clave}=" "$archivo" 2>/dev/null || true)"
    valor="${valor#*=}"
    valor="${valor%\"}"; valor="${valor#\"}"
    valor="${valor%\'}"; valor="${valor#\'}"
    printf '%s' "$valor"
}

# El valor efectivo de las ENRO_* lo define Compose desde el .env que vive al lado
# de docker-compose.yml (no desde el shell). Sin leerlo, el script imprimía y
# testeaba el puerto 8000 aunque el server estuviera servido en el 80.
valor_compose() {
    local clave="$1" valor=""
    [ -f .env ] && valor="$(valor_env .env "$clave")"
    [ -z "$valor" ] && valor="$(printenv "$clave" || true)"
    printf '%s' "$valor"
}

# Puerto público del nginx del compose; coincide con el default de compose.
HTTP_PORT="$(valor_compose ENRO_HTTP_PORT)"
[ -n "$HTTP_PORT" ] || HTTP_PORT=8000

echo "=== Sistema ENRO - Publicación (Linux / Docker) ==="

# ---- 0. Preconditions --------------------------------------------------------
if ! docker compose version >/dev/null 2>&1; then
    echo "ERROR: 'docker compose' (v2) no está disponible."
    echo "       Instalalo: https://docs.docker.com/compose/install/linux/"
    exit 1
fi

if [ ! -f backend/.env.docker ]; then
    echo "ERROR: falta backend/.env.docker (es el env_file del compose)."
    exit 1
fi

# ---- 0.1 Preflight de configuración ------------------------------------------
# Estos valores son los que convierten "funciona en mi máquina" en "funciona en
# el server". Se revisa ANTES de construir nada: levantar el stack con la config
# default da un sistema que arranca OK y falla en cosas que nadie prueba el día 1
# (el mail de recuperación que no abre, las cookies ilegibles con la key pública
# del repo, o MySQL con clave "root" escuchando).
echo "[0/5] Chequeo de configuración del server..."
fallos=0

fallar() { echo "  ERROR: $1"; fallos=$((fallos + 1)); }
avisar() { echo "  AVISO: $1"; }

# a) APP_KEY: la del repo está publicada en el remoto, así que no cifra nada de
#    verdad. Vacía o con la clave corta del scaffold = arranque con cookies ilegibles.
app_key="$(valor_env backend/.env.docker APP_KEY)"
case "$app_key" in
    "") fallar "APP_KEY vacía en backend/.env.docker. Generarla y ponerla ahí:
         docker compose exec backend php artisan key:generate --show
         docker compose restart backend" ;;
    base64:*)
        # AES-256 pide 32 bytes: 44 caracteres base64 (43 + '=').
        if [ "${#app_key}" -lt 51 ]; then
            fallar "APP_KEY demasiado corta en backend/.env.docker (esperada: base64 de 32 bytes)."
        fi ;;
    *) fallar "APP_KEY debe empezar con 'base64:' en backend/.env.docker." ;;
esac

# b) APP_DEBUG: con true, cualquier error 500 muestra la traza con SQL y valores.
if [ "$(valor_env backend/.env.docker APP_DEBUG)" = "true" ]; then
    fallar "APP_DEBUG=true en backend/.env.docker: los errores mostrarían consultas SQL en la pantalla."
fi

# c) Orígenes: localhost funciona en el server y en ningún otro lado.
for clave in APP_URL FRONTEND_URL; do
    url="$(valor_env backend/.env.docker "$clave")"
    case "$url" in
        "")     fallar "falta ${clave} en backend/.env.docker." ;;
        *localhost*|*127.0.0.1*)
            fallar "${clave}=${url} apunta a localhost: desde el notebook de un preceptor no abre.
         Usar la IP o el dominio real, ej. http://192.168.1.20 (es el link de 'recuperar contraseña')." ;;
    esac
done

# d) Clave de MySQL: compose arma DB_PASSWORD y la del contenedor desde el mismo
#    ENRO_DB_PASSWORD; si nadie lo puso, la base de 1200 alumnos queda en root/root.
db_pass="$(valor_compose ENRO_DB_PASSWORD)"
case "$db_pass" in
    ""|root) fallar "ENRO_DB_PASSWORD está vacío o es 'root'. Crear/editar el .env al lado de docker-compose.yml:
         ENRO_DB_PASSWORD=una-clave-larga-y-privada" ;;
esac

# e) Mailpit es una bandeja local: los correos de recupero nunca salen a la escuela.
if [ "$(valor_env backend/.env.docker MAIL_HOST)" = "mailpit" ]; then
    avisar "MAIL_HOST=mailpit: los correos se quedan en la bandeja local (http://127.0.0.1:8025).
         Para correo real: MAIL_* del SMTP del colegio y
         docker compose up -d --scale mailpit=0"
fi

# f) Los dos cron: sin ellos el volcado nocturno no existe y backups:prune nunca
#    corre (la carpeta storage/app/backups crece con un .sql de la base entera
#    cada vez que alguien aprieta el botón de la pantalla).
if command -v crontab >/dev/null 2>&1; then
    tareas="$(crontab -l 2>/dev/null || true)"
    cron_falta=0
    printf '%s\n' "${tareas}" | grep -Eq 'ENRO_RESPALDO=1|backups/enro' || {
        cron_falta=1; avisar "no hay cron del volcado nocturno de la base."; }
    printf '%s\n' "${tareas}" | grep -Eq 'ENRO_SCHEDULE=1|enro-schedule.log' || {
        cron_falta=1; avisar "no hay cron de 'php artisan schedule:run': backups:prune no corre y storage/app/backups nunca se limpia."; }
    [ "${cron_falta}" = "1" ] && avisar "agendar los dos de una vez: bash scripts/instalar-cron.sh"
else
    avisar "no existe 'crontab' en este usuario: agendar scripts/respaldar-bd.sh y schedule:run con el planificador del server."
fi

if [ "$fallos" -gt 0 ]; then
    echo
    echo "Abortado: ${fallos} error(es) de configuración. Ningún contenedor fue tocado."
    echo "Está todo explicado en docs/DESPLEGUE-LINUX.md (§3 y §4)."
    exit 1
fi
echo "      Configuración OK"

# El bundle del frontend se compila con VITE_API_BASE quemada; el default "/api"
# es el correcto para este nginx de un solo origen, así que no se toca.
API_BASE="${VITE_API_BASE:-/api}"
echo "      API base del bundle: ${API_BASE}   |   puerto público: ${HTTP_PORT}"

# ---- 1. Imágenes -------------------------------------------------------------
# `build` sin args usa los defaults del compose (VITE_API_BASE=/api). En el
# server alcanza con reconstruir cuando cambia el código del frontend.
echo "[1/5] Construyendo imágenes..."
docker compose build

# ---- 2. Contenedores ---------------------------------------------------------
echo "[2/5] Levantando contenedores..."
docker compose up -d

# El servicio mysql tiene healthcheck: se espera a que esté sano antes de migrar,
# o la primera migración de un server recién formateado falla con "connection refused".
echo "      Esperando a que MySQL esté sano..."
for _ in $(seq 1 60); do
    estado="$(docker inspect --format '{{.State.Health.Status}}' enro-mysql 2>/dev/null || echo starting)"
    [ "$estado" = "healthy" ] && break
    sleep 2
done
if [ "$estado" != "healthy" ]; then
    echo "ERROR: MySQL no quedó sano (estado: ${estado})."
    echo "       Ver: docker compose logs mysql"
    exit 1
fi

# ---- 3. Dependencias PHP -----------------------------------------------------
# --optimize-autoloader genera el classmap: sin él Laravel busca cada clase
# recorriendo PSR-4, y en un server con varios preceptores entrando a la vez
# esos milisegundos por request se notan. Se corre siempre (es idempotente y
# respeta composer.lock). No se usa --no-dev para no borrar las dependencias
# de pruebas si alguien corre phpunit en el mismo server.
echo "[3/5] Instalando dependencias de Composer (autoloader optimizado)..."
docker compose exec -T backend composer install \
    --no-interaction --prefer-dist --optimize-autoloader

# ---- 4. Base de datos --------------------------------------------------------
echo "[4/5] Ejecutando migraciones..."
docker compose exec -T backend php artisan migrate --force

# Compara la base real contra lo que las migraciones construirían desde cero.
# Sirve para cazarse mutuamente: un ALTER a mano en el server (para "desempolvar"
# un lunes) es exactamente el tipo de cambio que después rompe el despliegue del
# colegio nuevo, porque no existe en ningún archivo del repo. No aborta el arranque:
# el sistema funciona igual, pero la diferencia hay que convertirla en migración.
echo "      Verificando que el esquema coincida con las migraciones..."
if docker compose exec -T backend php artisan esquema:verificar; then
    echo "      Esquema OK"
else
    echo "      AVISO: hay diferencias entre la base y las migraciones (arriba está la lista)."
    echo "             Cada diferencia es un ALTER que se hizo a mano y necesita su migración."
fi

# Enlace public/storage -> storage/app/public (documentos y fotos subidas).
# Si el enlace viene creado desde Windows dentro de ./backend montado por volumen,
# adentro del contenedor Linux apunta a un camino inexistente: storage:link lo
# recrea cuando hace falta y no hace nada si ya está bien.
docker compose exec -T backend php artisan storage:link || true

# PHP-FPM adentro de la imagen escribe como www-data (uid 82). Si ./backend llegó
# del host con otro dueño, el primer request revienta con "Permission denied" al
# intentar escribir storage/logs o bootstrap/cache. Se corrige solo, sobre los
# dos directorios que la app escribe.
docker compose exec -T backend chown -R www-data:www-data storage bootstrap/cache || true

# ---- 5. Verificación ---------------------------------------------------------
echo "[5/5] Verificando el API..."
if command -v curl >/dev/null 2>&1; then
    if curl -fsS "http://127.0.0.1:${HTTP_PORT}/api/ping" >/dev/null; then
        echo "      API respondiendo OK en /api/ping"
    else
        echo "AVISO: /api/ping no respondió. Revisar: docker compose logs backend nginx"
    fi
else
    echo "      (curl no instalado: verificar a mano http://127.0.0.1:${HTTP_PORT}/api/ping)"
fi

# -----------------------------------------------------------------------------
# Por qué ACA NO se corre 'php artisan config:cache' ni 'route:cache':
# ./backend está montado por volumen contra el repo del host, así que el archivo
# cacheado (bootstrap/cache/config.php) se escribe en el mismo disco que ve la
# máquina de desarrollo. Ese archivo congela los valores de environment del
# momento del cacheo (DB_HOST=mysql, etc.), y desde entonces 'php artisan serve'
# en el host intenta conectar a un host "mysql" que no existe y la app de
# escritorio se cae. Antes de activar el cacheo hay que separar los códigos
# (copiar ./backend dentro de la imagen en vez de montarlo). Ver docs/DESPLEGUE-LINUX.md.
# El cacheo de vistas tampoco conviene: las vistas Blade se compilan solas y el
# storage/ es compartido con el desarrollo.
# -----------------------------------------------------------------------------

echo
echo "=== Listo ==="
echo "  Sistema:      http://<IP-del-server>:${HTTP_PORT}"
echo "  API (ping):   http://<IP-del-server>:${HTTP_PORT}/api/ping"
echo "  MySQL:        solo 127.0.0.1:3307 (no expuesto a la red)"
echo "  Bandeja de correo de prueba: http://127.0.0.1:8025"
echo
echo "  Logs:         docker compose logs -f backend nginx"
echo "  Detener:      bash scripts/detener.sh"
