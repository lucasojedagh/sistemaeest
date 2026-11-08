#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/generar-sql.sh
# Propósito: dejar UN archivo .sql con TODO el sistema adentro —esquema y
#            datos— para llevar a otro MySQL y pegarlo en un panel (phpMyAdmin,
#            HeidiSQL, Workbench) o cargarlo por línea de comandos.
#
# Uso:  bash scripts/generar-sql.sh              # genera backups/sistema_escuela_COMPLETO_<fecha>.sql
#       bash scripts/generar-sql.sh --probar      # además lo carga en una base auxiliar y compara
#
# Los datos salen del MySQL local (Laragon). Si lo que querés copiar es la base
# del contenedor Docker, correr adentro de ese contenedor el mysqldump con las
# mismas banderas de abajo, o usar scripts/exportar-migracion.sh.
#
# OJO: el archivo tiene el nombre, DNI, mail y teléfono de cada alumno. Queda
# en backups/, que está en .gitignore; aun así, no mandar por mail ni subir a
# ningún lado, y borrarlo del server cuando termine el traslado.
# ==============================================================================

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

DB_NAME="${ENRO_SRC_DB_NAME:-sistema_escuela}"
DB_USER="${ENRO_SRC_DB_USER:-root}"
DB_PASS="${ENRO_SRC_DB_PASS:-}"
DB_HOST="${ENRO_SRC_DB_HOST:-127.0.0.1}"
DB_PORT="${ENRO_SRC_DB_PORT:-3306}"
SALIDA="${ENRO_SQL_OUT:-backups/${DB_NAME}_COMPLETO_$(date +%Y-%m-%d).sql}"
PRUEBA="${ENRO_SQL_TEST_DB:-${DB_NAME}_prueba_sql}"

PROBAR=0
for arg in "$@"; do
    case "${arg}" in
        --probar) PROBAR=1 ;;
        -h|--help) sed -n '3,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Opción desconocida: ${arg}" >&2; exit 2 ;;
    esac
done

abortar() { echo "ERROR: $*" >&2; exit 1; }

# Cliente de MySQL: en Git Bash no está en el PATH, Laragon lo tiene en
# C:/laragon/bin/mysql/<versión>/bin.
if command -v mysqldump >/dev/null 2>&1; then
    MYSQL="mysql"; DUMP="mysqldump"
elif BIN_LARAGON="$(ls -d /c/laragon/bin/mysql/*/bin 2>/dev/null | sort -V | tail -1)" && [ -x "${BIN_LARAGON}/mysqldump.exe" ]; then
    MYSQL="${BIN_LARAGON}/mysql.exe"
    DUMP="${BIN_LARAGON}/mysqldump.exe"
else
    abortar "no encuentro mysqldump. Verificar que Laragon tenga MySQL instalado."
fi

# --result-file en vez de `>`: mysqldump escribe el archivo y el shell de
# Windows no toca ni un byte (la redirección puede recodificar).
TMP="$(mktemp).sql"
trap 'rm -f "${TMP}"' EXIT

echo "[1/4] Volcando ${DB_NAME} desde ${DB_HOST}:${DB_PORT}"
MYSQL_PWD="${DB_PASS}" "${DUMP}" \
    -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
    --single-transaction --routines --triggers --no-tablespaces \
    --default-character-set=utf8mb4 --set-gtid-purged=OFF \
    --skip-extended-insert --complete-insert \
    --result-file="${TMP}" "${DB_NAME}"

# El cierre lo escribe mysqldump cuando termina la última tabla: si falta, el
# archivo está cortado y pegarlo dejaría una base a medias.
tail -c 200 "${TMP}" | grep -q 'Dump completed' \
    || abortar "el volcado no tiene el cierre de mysqldump (quedó truncado); no lo escribo."

# --skip-extended-insert = un INSERT por fila, --complete-insert = con los
# nombres de las columnas. El archivo es más grande pero se puede revisar a
# mano, y si una columna cambia de orden no se desfasan los valores.
mkdir -p "$(dirname "${SALIDA}")"

consulta() {
    MYSQL_PWD="${DB_PASS}" "${MYSQL}" -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -N -B "$@"
}

TABLAS="$(consulta -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'" | tr -d '\r')"
ALUMNOS="$(consulta "${DB_NAME}" -e "SELECT COUNT(*) FROM alumnos" | tr -d '\r')"
MIG="$(consulta "${DB_NAME}" -e "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1" | tr -d '\r' | head -1)"
VERSION="$(consulta -e "SELECT VERSION()" | tr -d '\r')"

echo "[2/4] Escribiendo ${SALIDA}"
{
    printf -- '-- ============================================================================\n'
    printf -- '-- Sistema ENRO - base de datos COMPLETA (esquema + datos)\n'
    printf -- '-- Generado: %s desde %s (MySQL %s), base %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${DB_HOST}:${DB_PORT}" "${VERSION}" "${DB_NAME}"
    printf -- '-- %s tablas, %s alumnos. Ultima migracion aplicada: %s\n' "${TABLAS}" "${ALUMNOS}" "${MIG}"
    printf -- '--\n'
    printf -- '-- COMO USARLO\n'
    printf -- '--   a) Pegar en un panel (phpMyAdmin / HeidiSQL / Workbench): elegir la conexion\n'
    printf -- '--      y pegar. Crea la base si no existe y carga adentro. Las tablas que ya\n'
    printf -- '--      esten en %s se reemplazan: el archivo trae un DROP TABLE por tabla,\n' "${DB_NAME}"
    printf -- '--      y no toca ninguna otra base del server.\n'
    printf -- '--   b) Por linea de comandos:\n'
    printf -- '--        mysql -u root -p < %s\n' "$(basename "${SALIDA}")"
    printf -- '--      y en un server con el stack Docker de este repo:\n'
    printf -- '--        docker compose cp %s mysql:/tmp/bd.sql\n' "$(basename "${SALIDA}")"
    printf -- '--        docker compose exec -T mysql sh -c '"'"'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /tmp/bd.sql'"'"'\n'
    printf -- '--\n'
    printf -- '-- LO QUE NO TRAE: los archivos del modulo Documentos y las fotos de perfil no\n'
    printf -- '--   estan en la base, son archivos en backend/storage/app/documentos y\n'
    printf -- '--   .../avatars. Si no los copias, la pantalla de Documentos muestra la lista\n'
    printf -- '--   y cada descarga da error (la tabla guarda el nombre del archivo).\n'
    printf -- '-- ============================================================================\n'
    printf -- 'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "${DB_NAME}"
    printf -- 'USE `%s`;\n' "${DB_NAME}"
    printf -- 'SET NAMES utf8mb4;\n'
    cat "${TMP}"
} > "${SALIDA}"

LINEAS="$(grep -c '' "${SALIDA}")"
echo "       ${SALIDA} ($(du -h "${SALIDA}" | cut -f1), ${LINEAS} líneas)"
echo "       sha256: $(sha256sum "${SALIDA}" | awk '{print $1}')"

# ==============================================================================
# 3-4. Prueba opcional: cargarlo de verdad y comparar
# ==============================================================================
if [ "${PROBAR}" -ne 1 ]; then
    echo "[3/4] Salteo la prueba (correr con --probar para cargarlo en una base auxiliar)"
    echo "[4/4] Listo"
    echo ""
    echo "Para llevarlo al server: scp ${SALIDA} usuario@server:/tmp/ y pegar el contenido"
    echo "en el panel, o mysql -u root -p < el-archivo.sql. Los documentos subidos van aparte."
    exit 0
fi

echo "[3/4] Probándolo: lo cargo en '${PRUEBA}' y comparo contra ${DB_NAME}"

# Copia con el nombre de la base auxiliar: el archivo hace CREATE DATABASE y USE,
# así que sin este cambio la prueba escribiría sobre la base real.
PRUEBA_TMP="$(mktemp).sql"
trap 'rm -f "${TMP}" "${PRUEBA_TMP}"' EXIT
sed "s/\`${DB_NAME}\`/\`${PRUEBA}\`/g" "${SALIDA}" > "${PRUEBA_TMP}"

MYSQL_PWD="${DB_PASS}" "${MYSQL}" -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" < "${PRUEBA_TMP}" \
    || abortar "el mysql rechazó el archivo. La base auxiliar puede quedar a medias: ${PRUEBA}"

# CHECKSUM TABLE por tabla: si el archivo trajera las mismas cantidades de filas
# pero un valor cambiado, el checksum lo desnuda. Se arma sin backticks porque
# CHECKSUM TABLE con nombres entre backticks da error 1103 en MySQL 8.4.
LISTA_SRC=""; LISTA_DST=""
for t in $(consulta -e "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB_NAME}' ORDER BY table_name" | tr -d '\r'); do
    LISTA_SRC="${LISTA_SRC}${DB_NAME}.${t},"
    LISTA_DST="${LISTA_DST}${PRUEBA}.${t},"
done
CHK_SRC="$(consulta -e "CHECKSUM TABLE ${LISTA_SRC%,};" | awk -v p="${DB_NAME}." '{gsub(p,""); print $1"\t"$2}')"
CHK_DST="$(consulta -e "CHECKSUM TABLE ${LISTA_DST%,};" | awk -v p="${PRUEBA}." '{gsub(p,""); print $1"\t"$2}')"

N_SRC="$(printf '%s\n' "${CHK_SRC}" | grep -c .)"
N_DST="$(printf '%s\n' "${CHK_DST}" | grep -c .)"
DIF="$(diff <(printf '%s\n' "${CHK_SRC}") <(printf '%s\n' "${CHK_DST}") || true)"

echo "[4/4] Resultado de la prueba"
if [ "${N_SRC}" -ge 1 ] && [ "${N_SRC}" = "${N_DST}" ] && [ -z "${DIF}" ]; then
    echo "       ${N_DST} tablas cargadas desde el .sql, checksum idéntico en todas"
    echo "       ALUMNOS: $(consulta "${PRUEBA}" -e "SELECT COUNT(*) FROM alumnos" | tr -d '\r') (en ${DB_NAME} hay ${ALUMNOS})"
    echo ""
    echo "=== ARCHIVO CONFIABLE ==="
    echo "${SALIDA}"
else
    echo "       tablas origen=${N_SRC} prueba=${N_DST}"
    printf '%s\n' "${DIF}" | sed 's/^/       /'
    echo ""
    echo "=== NO USAR ESE ARCHIVO: la prueba no cuadró ==="
    echo "       Revisar qué cambió entre la carga y la base real. La auxiliar quedó en '${PRUEBA}'."
    exit 1
fi

echo ""
echo "La base auxiliar '${PRUEBA}' sigue creada para que la revises. Para tirarla:"
echo "  ${MYSQL} -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER} -e 'DROP DATABASE ${PRUEBA};'"
