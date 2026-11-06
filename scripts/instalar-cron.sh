#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/instalar-cron.sh
# Propósito: agendar el volcado nocturno de la base y su retención de 14 días.
#            Es el respaldo (b) de docs/DESPLEGUE-LINUX.md §7: el botón de la
#            pantalla descarga un .sql, pero nadie lo aprieta todos los días;
#            sin cron, el único antecedente de los 1200 alumnos es lo que
#            alguien haya bajado manualmente.
# Uso:  bash scripts/instalar-cron.sh            (funciona desde cualquier carpeta)
#       ENRO_BACKUP_DIR=/otro/disco bash scripts/instalar-cron.sh
# Idempotente: las dos líneas que agrega van marcadas con un centinela, así que
# ejecutarlo otra vez las reemplaza en lugar de duplicarlas.
# ==============================================================================

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
REPO="$(pwd)"
BACKUP_DIR="${ENRO_BACKUP_DIR:-/var/backups/enro}"
CENTINELA="# ENRO-respaldos (scripts/instalar-cron.sh)"
RETENCION_DIAS=14

echo "=== Sistema ENRO - Cron de respaldos ==="
echo "  Repositorio:  ${REPO}"
echo "  Respaldos en: ${BACKUP_DIR}"

if ! command -v crontab >/dev/null 2>&1; then
    echo "ERROR: no hay 'crontab' en este PATH."
    echo "       El agendamiento es una sola línea que llama a"
    echo "         bash ${PWD}/scripts/respaldar-bd.sh"
    echo "       Servida para cualquier planificador (timer de systemd, anacron,"
    echo "       panel de hosting). Donde sea que lo agendes, que corra ese script"
    echo "       y no el mysqldump suelto: el script es el que verifica el resultado."
    exit 1
fi

if [ ! -f scripts/respaldar-bd.sh ]; then
    echo "ERROR: falta scripts/respaldar-bd.sh (el que hace el volcado)."
    exit 1
fi

# El directorio de destino: cron corre con un PATH mínimo y sin cd, así que la
# ruta tiene que existir y ser escribible por el usuario que agenda.
if ! mkdir -p "${BACKUP_DIR}" 2>/dev/null; then
    echo "ERROR: no se pudo crear ${BACKUP_DIR}."
    echo "       Crearlo con permisos para este usuario, o indicar otra ruta:"
    echo "         ENRO_BACKUP_DIR=/ruta/escribible bash scripts/instalar-cron.sh"
    exit 1
fi
if [ ! -w "${BACKUP_DIR}" ]; then
    echo "ERROR: ${BACKUP_DIR} existe pero no es escribible por $(whoami)."
    exit 1
fi

# Prueba real de que este usuario puede hablar con Docker: si no está en el
# grupo docker, el cron se ejecuta igual y falla, y eso se nota meses después,
# cuando hace falta el respaldo. Se avisa acá, no en agosto.
if ! docker compose -f "${REPO}/docker-compose.yml" ps >/dev/null 2>&1; then
    echo "AVISO: 'docker compose ps' no responde para este usuario."
    echo "       El cron necesita poder mandar comandos a los contenedores:"
    echo "         sudo usermod -aG docker $(whoami)    (y volver a entrar a la sesión)"
    echo "       Si igual seguís, el agendamiento queda escrito pero las corridas van a fallar."
fi

# La línea agendada es mínima a propósito: solo llama a scripts/respaldar-bd.sh y
# redirectiona SU salida. El dump, el gzip, la verificación y la retención viven
# en ese script, y ahí sí se pueden probar a mano.
# Poner todo en el crontab como estaba documentado fallaba en silencio: el
# `>> cron.log` del final redirecciona el stdout de gzip DESPUÉS del `> bd.sql.gz`,
# así que el .gz quedaba de 0 bytes y el binario iba al log. En cron nadie mira.
# ENRO_RESPALDO=1 es una asignación de variable al inicio (el cron la acepta y la
# ignora): es la marca con la que este script reconoce sus propias líneas y puede
# reemplazarlas. Sin marca, "idempotente" dependería de que la ruta del repo no
# cambie nunca, y la primera reinstalación terminaría con dos volcados por día.
LINEA_CRON="0 3 * * * ENRO_RESPALDO=1 ENRO_BACKUP_DIR=${BACKUP_DIR} ENRO_BACKUP_RETENCION=${RETENCION_DIAS} bash ${REPO}/scripts/respaldar-bd.sh >> ${BACKUP_DIR}/cron.log 2>&1"

# El otro cron que falta es el del planificador de Laravel. app/Console/Kernel.php
# programa `backups:prune --dias=30` los domingos a las 3, pero schedule:run solo
# corre si existe la entrada de cada minuto: sin ella, backend/storage/app/backups
# (los .sql que baja la pantalla de Respaldos, con la base entera adentro) no se
# borra jamás y se come el disco del server.
LINEA_SCHEDULE="* * * * * ENRO_SCHEDULE=1 cd ${REPO} && docker compose exec -T backend php artisan schedule:run >> ${BACKUP_DIR}/schedule.log 2>&1"

actual="$(crontab -l 2>/dev/null || true)"

# Se saca lo que instaló este script y también las líneas de la versión antigua
# que está documentada a mano, para no dejar un duplicado viejo conviviendo con
# el nuevo. Los patrones son específicos del ENRO (el nombre del archivo de
# salida, el log enrorado): si se filtrara por 'artisan schedule:run' a secas,
# este script borraría el cron de cualquier otra app Laravel del server.
# Todo lo ajeno al sistema queda intacto.
limpia="$(printf '%s\n' "${actual}" \
    | grep -vF -e 'ENRO_RESPALDO=1' \
                -e 'ENRO_SCHEDULE=1' \
                -e 'bd_$(date' \
                -e "name 'bd_*.sql.gz'" \
                -e 'respaldar-bd.sh' \
                -e 'enro-schedule.log' \
                -e "${CENTINELA}" \
    || true)"

{
    [ -n "${limpia}" ] && printf '%s\n' "${limpia}"
    printf '%s\n' "${CENTINELA}"
    printf '%s\n' "${LINEA_CRON}"
    printf '%s\n' "${LINEA_SCHEDULE}"
} | crontab -

echo
echo "Agendado. Crontab del usuario $(whoami):"
crontab -l | grep -A2 -F "${CENTINELA}" | sed 's/^/  /'
echo
echo "  Qué hace:"
echo "    03:00  volcado .sql.gz + verificación + borra los de más de ${RETENCION_DIAS} días"
echo "    cada minuto  schedule:run de Laravel, que es lo que hace correr backups:prune"
echo "           (borra los .sql de la pantalla de Respaldos más viejos de 30 días)"
echo "  Salidas de las corridas: ${BACKUP_DIR}/cron.log y ${BACKUP_DIR}/schedule.log."
echo "  (Van ahí y no a /var/log porque ese directorio pide sudo.)"
echo
echo "  Probarlo ahora mismo, sin esperar a las 3:"
echo "    ENRO_BACKUP_DIR=${BACKUP_DIR} bash scripts/respaldar-bd.sh"
echo
echo "  Falta un paso que ningún script puede hacer por vos: copiar ${BACKUP_DIR}"
echo "  fuera de este server al menos una vez por semana. El respaldo que vive"
echo "  junto a la base no sirve si se muere el disco."
