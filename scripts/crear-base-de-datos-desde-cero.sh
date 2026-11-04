#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/crear-base-de-datos-desde-cero.sh
# Propósito: Elimina y recrea la base de datos `sistema_escuela` desde cero,
#            ejecuta todas las migraciones y seeders (incluyendo datos demo).
# Uso: ./scripts/crear-base-de-datos-desde-cero.sh  (desde la raíz del repo)
# Requisitos: Docker containers en ejecución (ver scripts/iniciar.ps1).
#             Los valores de conexión son los mismos que define docker-compose.yml.
# ==============================================================================

set -euo pipefail

BACKEND_CONTAINER="${BACKEND_CONTAINER:-enro-backend}"
DB_CONTAINER="${DB_CONTAINER:-enro-mysql}"
DB_NAME="${DB_NAME:-sistema_escuela}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-${ENRO_DB_PASSWORD:-root}}"

echo "=== SISTEMA ENRO - Limpieza y recreación de BD ==="

# ---- 1. Verificar contenedores ----
echo "[1/5] Verificando contenedores..."
docker ps --format '{{.Names}}' | grep -q "$BACKEND_CONTAINER" || {
    echo "ERROR: El contenedor '$BACKEND_CONTAINER' no está en ejecución."
    exit 1
}
echo "  OK"

# ---- 2. Backup opcional ----
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="$(dirname "$0")/../backups"
mkdir -p "$BACKUP_DIR"
echo "[2/5] Haciendo backup de la BD actual..."
# Vía /tmp del contenedor + docker cp: bytes exactos (utf8mb4 intacto).
# La redirección directa `>` del shell local puede recodificar el texto.
if docker exec "$DB_CONTAINER" mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" --result-file=/tmp/backup.sql 2>/dev/null \
    && docker cp "$DB_CONTAINER:/tmp/backup.sql" "$BACKUP_DIR/backup-${DB_NAME}-${TIMESTAMP}.sql" >/dev/null 2>&1 \
    && [ -s "$BACKUP_DIR/backup-${DB_NAME}-${TIMESTAMP}.sql" ]; then
    echo "  Backup guardado en: backups/backup-${DB_NAME}-${TIMESTAMP}.sql"
else
    echo "  Backup omitido (no crítico)"
fi

# ---- 3. Eliminar y recrear BD ----
echo "[3/5] Eliminando y recreando BD '$DB_NAME'..."
docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" <<< "
    DROP DATABASE IF EXISTS $DB_NAME;
    CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
echo "  BD '$DB_NAME' recreada."

# ---- 4. Migraciones ----
# OJO: con `set -o pipefail`, un grep sin coincidencias (ej. "Nothing to
# migrate") devuelve 1 y abortaría el script. Por eso se captura la salida
# primero y el grep lleva `|| true`; el que manda es el exit de artisan.
echo "[4/5] Ejecutando migraciones..."
MIGRATE_OUT=$(docker exec -w /var/www "$BACKEND_CONTAINER" php artisan migrate --force 2>&1) || {
    echo "ERROR durante las migraciones."
    echo "$MIGRATE_OUT" | sed 's/^/    /'
    exit 1
}
echo "$MIGRATE_OUT" | grep -E 'DONE|Ran|Migrated|Nothing to migrate' | sed 's/^/    /' || true
echo "  Migraciones completadas."

# ---- 5. Seeders ----
echo "[5/5] Ejecutando seeders..."
SEED_OUT=$(docker exec -w /var/www "$BACKEND_CONTAINER" php artisan db:seed --force 2>&1) || {
    echo "ERROR durante los seeders."
    echo "$SEED_OUT" | sed 's/^/    /'
    exit 1
}
echo "$SEED_OUT" | grep -E 'creado|exist|Datos|Seeding' | sed 's/^/    /' || true
echo "  Seeders completados."

echo ""
echo "=== BD LIMPIA Y OPERATIVA ==="
echo "Usuario admin: el que muestre la salida del seeder (ADMIN_EMAIL / clave generada o ADMIN_PASSWORD)"
echo "Backend: http://localhost:8000/api"
echo "Frontend: http://localhost:5173"
