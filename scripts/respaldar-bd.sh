#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/respaldar-bd.sh
# Propósito: volcado nocturno de la base de datos del sistema (es el respaldo
#            "b" de docs/DESPLEGUE-LINUX.md §7). Lo agenda scripts/instalar-cron.sh
#            y también se puede correr a mano antes de un cambio riesgoso:
#                bash scripts/respaldar-bd.sh
#            Sale un único archivo bd_AAAA-MM-DD.sql.gz por día; si ya existe,
#            lo pisa (el cron corre a las 3, una vez por día).
# Cuidado: si se copiara la línea directo al crontab, el `>> cron.log` del final
#            se comería el stdout de gzip y el .gz quedaría vacío. Por eso el pipe
#            vive acá y el cron solo agenda y redirige.
# ==============================================================================

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

BACKUP_DIR="${ENRO_BACKUP_DIR:-/var/backups/enro}"
DB_NAME="${ENRO_DB_NAME:-sistema_escuela}"
RETENCION_DIAS="${ENRO_BACKUP_RETENCION:-14}"
destino="${BACKUP_DIR}/bd_$(date +%F).sql.gz"

mkdir -p "${BACKUP_DIR}"

# `docker compose exec -T`: sin TTY. Con cron no hay terminal, y el -T omitido
# da "the input device is not a TTY" y una corrida fallida que nadie ve.
# mysqldump corre DENTRO del contenedor de MySQL: la imagen de PHP no trae
# cliente, y el cliente de MariaDB no habla el método de autenticación default
# de MySQL 8 (falla en silencio, deja un volcado vacío).
# --single-transaction snapshot de InnoDB sin bloquear tablas: si los preceptores
# entran temprano, un dump con bloqueos los deja colgados y el sistema parece caído.
# La clave se lee del entorno del contenedor ($MYSQL_ROOT_PASSWORD), nunca de un
# archivo del repo.
docker compose -f docker-compose.yml exec -T mysql sh -c \
    'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$1"' \
    sh "${DB_NAME}" | gzip > "${destino}"

# Verificación real, no "salió 0": mysqldump puede terminar sin errores y
# volcar cualquier cosa. El marcador del final es lo que prueba que el dump
# está completo y el gzip -t que el archivo no se truncó.
if [ ! -s "${destino}" ]; then
    echo "ERROR: ${destino} quedó vacío. Revisar docker compose ps y las credenciales." >&2
    rm -f "${destino}"
    exit 1
fi
gzip -t "${destino}"
if ! gzip -dc "${destino}" | tail -c 400 | grep -q 'Dump completed'; then
    echo "ERROR: ${destino} no tiene el cierre de mysqldump: el volcado está truncado." >&2
    # Se borra: un .gz truncado es peor que no tener nada, porque la
    # restauración lo acepta y se corta a mitad.
    rm -f "${destino}"
    exit 1
fi

# Retención: los archivos de 1200 alumnos ocupan decenas de MB por día y un
# server de colegio se queda sin disco sin avisar.
find "${BACKUP_DIR}" -name 'bd_*.sql.gz' -mtime +"${RETENCION_DIAS}" -delete

tamanio="$(du -h "${destino}" | cut -f1)"
echo "Respaldado: ${destino} (${tamanio}, retención ${RETENCION_DIAS} días)"
echo "ACORDARSE: copiar ${BACKUP_DIR} fuera de este server al menos una vez por semana."
