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
# ==============================================================================

set -euo pipefail

# El script se mueve a la raíz del repo (donde está docker-compose.yml).
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Puerto público del nginx del compose; coincide con el default de compose.
HTTP_PORT="${ENRO_HTTP_PORT:-8000}"

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
