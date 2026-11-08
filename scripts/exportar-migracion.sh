#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/exportar-migracion.sh
# Propósito: armar UN archivo con todo lo que hay que llevarse a otro servidor:
#            la base de datos completa, los archivos subidos (documentos y
#            fotos de perfil) y un manifiesto con la cantidad de filas de cada
#            tabla para poder comprobar, del otro lado, que no faltó nada.
#
# Uso:  bash scripts/exportar-migracion.sh            # detecta el origen
#       bash scripts/exportar-migracion.sh --local    # fuerza Laragon / MySQL local
#       bash scripts/exportar-migracion.sh --docker    # fuerza el contenedor enro-mysql
#
# Salida: exportaciones-enro/migracion-ENRO-AAAA-MM-DD-HHMM.tar.gz
#
# ADVERTENCIA: el paquete tiene los datos reales de los alumnos (nombres, DNIs,
#            mails, teléfonos). No se sube a ningún lado (está en .gitignore),
#            no se manda por mail y se borra apenas termine el traslado.
# ==============================================================================

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

DB_NAME="${ENRO_SRC_DB_NAME:-sistema_escuela}"
DB_USER="${ENRO_SRC_DB_USER:-root}"
DB_PASS="${ENRO_SRC_DB_PASS:-}"
DB_HOST="${ENRO_SRC_DB_HOST:-127.0.0.1}"
DB_PORT="${ENRO_SRC_DB_PORT:-3306}"
SALIDA_DIR="${ENRO_EXPORT_DIR:-exportaciones-enro}"
STORAGE="backend/storage/app"
ARCHIVOS=(documentos avatars)

MODO=""
for arg in "$@"; do
    case "${arg}" in
        --local)  MODO=local ;;
        --docker) MODO=docker ;;
        -h|--help)
            sed -n '3,19p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)
            echo "Opción desconocida: ${arg} (probar --local o --docker)" >&2
            exit 2 ;;
    esac
done

paso() { echo "[${1}/5] ${2}"; }
abortar() { echo "ERROR: $*" >&2; exit 1; }

MB() { awk -v b="${1}" 'BEGIN{printf "%.1f MB", b/1048576}'; }

# ==============================================================================
# 1. Identificar el origen y armar las dos funciones de acceso
#    (sql = consulta chica, volcar = mysqldump a archivo)
# ==============================================================================
paso 1 "Identificando el origen de los datos"

# En Git Bash (Windows) los clientes de MySQL no están en el PATH: Laragon los
# guarda en C:/laragon/bin/mysql/<versión>/bin. Se toma la carpeta más nueva.
carpeta_bin_laragon() {
    for carpeta in /c/laragon/bin/mysql/*/bin /c/laragon/mysql/bin /c/xampp/mysql/bin; do
        if [ -f "${carpeta}/mysqldump.exe" ] || [ -f "${carpeta}/mysqldump" ]; then
            printf '%s' "${carpeta}"
            return 0
        fi
    done
    return 1
}

docker_arriba() {
    docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'enro-mysql'
}

BIN_LARAGON=""
if command -v mysqldump >/dev/null 2>&1; then
    MYSQL_BIN="mysql"
    DUMP_BIN="mysqldump"
elif BIN_LARAGON="$(carpeta_bin_laragon)" && [ -n "${BIN_LARAGON}" ]; then
    MYSQL_BIN="${BIN_LARAGON}/mysql.exe"
    DUMP_BIN="${BIN_LARAGON}/mysqldump.exe"
    [ -f "${MYSQL_BIN}" ] || MYSQL_BIN="${BIN_LARAGON}/mysql"
    [ -f "${DUMP_BIN}" ] || DUMP_BIN="${BIN_LARAGON}/mysqldump"
else
    MYSQL_BIN=""
    DUMP_BIN=""
fi

HAY_LOCAL=0
if [ -n "${MYSQL_BIN}" ]; then
    if sql_local_test="$(MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" -h "${DB_HOST}" -P "${DB_PORT}" \
            -u "${DB_USER}" -N -B -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.alumnos" 2>/dev/null)"; then
        HAY_LOCAL=1
    fi
fi

HAY_DOCKER=0
if docker_arriba; then
    if docker compose -f docker-compose.yml exec -T mysql sh -c \
        'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e "SELECT 1" >/dev/null' 2>/dev/null; then
        HAY_DOCKER=1
    fi
fi

if [ -z "${MODO}" ]; then
    if [ "${HAY_DOCKER}" = 1 ] && [ "${HAY_LOCAL}" = 1 ]; then
        abortar "hay un contenedor enro-mysql arriba y además responde un MySQL local en ${DB_HOST}:${DB_PORT}, los dos con la base '${DB_NAME}'.
       Decidir cuál tiene los datos que te querés llevar:
         bash scripts/exportar-migracion.sh --local
         bash scripts/exportar-migracion.sh --docker"
    fi
    if [ "${HAY_DOCKER}" = 1 ]; then
        MODO=docker
    elif [ "${HAY_LOCAL}" = 1 ]; then
        MODO=local
    else
        abortar "no encuentro ni el contenedor enro-mysql ni un MySQL local con la base '${DB_NAME}'.
       Si la base está en otro lado:
         ENRO_SRC_DB_HOST=ip ENRO_SRC_DB_PORT=3306 ENRO_SRC_DB_USER=root ENRO_SRC_DB_PASS=clave \\
           bash scripts/exportar-migracion.sh --local"
    fi
fi

if [ "${MODO}" = "docker" ]; then
    docker_arriba || abortar "pediste --docker pero el contenedor enro-mysql no está corriendo (mirar: docker compose ps)."
    ORIGEN_DESC="contenedor Docker de este repo (enro-mysql)"
else
    [ -n "${MYSQL_BIN}" ] || abortar "no encuentro el cliente de MySQL. En Laragon está en C:/laragon/bin/mysql/<versión>/bin."
    ORIGEN_DESC="MySQL local en ${DB_HOST}:${DB_PORT} (usuario ${DB_USER})"
fi

# Una sola función para los dos orígenes: `-N -B` = sin adornos, separador de
# campo tab, así la salida se puede leer con awk sin parsers frágiles.
sql() {
    if [ "${MODO}" = "docker" ]; then
        docker compose -f docker-compose.yml exec -T mysql sh -c \
            'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B "$1" -e "$2"' \
            sh "${DB_NAME}" "${1}"
    else
        MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" -h "${DB_HOST}" -P "${DB_PORT}" \
            -u "${DB_USER}" -N -B "${DB_NAME}" -e "${1}"
    fi
}

echo "       Origen: ${ORIGEN_DESC}"
echo "       Base:   ${DB_NAME}"

# La versión importa del otro lado: un dump de 8.4 restaurado en MySQL 8.0 anda
# (las tablas de este sistema usan utf8mb4_unicode_ci, no los collations nuevos),
# pero si alguien exporta desde MariaDB o 5.7 conviene saberlo antes de intentar.
VERSION_MYSQL="$(sql "SELECT VERSION()" | tr -d '\r')"
echo "       Versión de MySQL de origen: ${VERSION_MYSQL}"

# ==============================================================================
# 2. Carpeta de trabajo
# ==============================================================================
paso 2 "Preparando la carpeta de trabajo"

MARCA="$(date +%Y-%m-%d-%H%M)"
PAQUETE="migracion-ENRO-${MARCA}"
TMP="${SALIDA_DIR}/.trabajo-$$"
DIR="${TMP}/${PAQUETE}"
SQL_CRUDO="${TMP}/bd.sql"
mkdir -p "${DIR}"
trap 'rm -rf "${TMP}"' EXIT

# ==============================================================================
# 3. Volcado de la base
# ==============================================================================
paso 3 "Volcando la base de datos"

# --result-file en vez de `>`: escribe el archivo desde el propio mysqldump,
# bytes exactos, sin que el shell de Windows recodifique ni cambie saltos de
# línea en el medio (y sin un pipe de por medio que pueda cortarse).
# --single-transaction lee un snapshot de InnoDB sin bloquear: si un preceptor
# entra mientras se exporta, no se queda colgado.
if [ "${MODO}" = "docker" ]; then
    docker compose -f docker-compose.yml exec -T mysql sh -c \
        'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers \
         --no-tablespaces --default-character-set=utf8mb4 --result-file=/tmp/enro_export.sql "$1" \
         && chmod 666 /tmp/enro_export.sql' \
        sh "${DB_NAME}"
    # `docker compose cp` existe desde compose 2.23; en servidores con un Docker
    # más viejo se cae el script justo después de volcar. El `docker cp` directo
    # al nombre del contenedor funciona en cualquier versión, así que se usa de
    # respaldo sin preguntar.
    docker compose -f docker-compose.yml cp "mysql:/tmp/enro_export.sql" "${SQL_CRUDO}" \
        || docker cp "enro-mysql:/tmp/enro_export.sql" "${SQL_CRUDO}" \
        || abortar "no pude sacar el volcado del contenedor (ni con 'docker compose cp' ni con 'docker cp')."
    docker compose -f docker-compose.yml exec -T mysql rm -f /tmp/enro_export.sql
else
    MYSQL_PWD="${DB_PASS}" "${DUMP_BIN}" \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
        --single-transaction --routines --triggers --no-tablespaces \
        --default-character-set=utf8mb4 --set-gtid-purged=OFF \
        --result-file="${SQL_CRUDO}" "${DB_NAME}"
fi

[ -s "${SQL_CRUDO}" ] || abortar "el volcado salió vacío. Revisar credenciales y que la base '${DB_NAME}' exista."
if ! tail -c 400 "${SQL_CRUDO}" | grep -q 'Dump completed'; then
    abortar "el volcado no tiene el cierre de mysqldump: está truncado y la restauración se cortaría a mitad."
fi

gzip -9 -c "${SQL_CRUDO}" > "${DIR}/bd.sql.gz"
rm -f "${SQL_CRUDO}"
BYTES_BD="$(wc -c < "${DIR}/bd.sql.gz" | tr -d '[:space:]')"
echo "       bd.sql.gz listo ($(MB "${BYTES_BD}") comprimido)"

# ==============================================================================
# 4. Manifiesto: filas por tabla
# ==============================================================================
paso 4 "Contando filas por tabla (esto es lo que se verifica del otro lado)"

TABLAS="$(sql "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_type='BASE TABLE' ORDER BY table_name" | tr -d '\r')"
[ -n "${TABLAS}" ] || abortar "no pude listar las tablas de '${DB_NAME}'."

# Una sola consulta con UNION ALL: 31 tablas se cuentan en un segundo y no se
# abren 31 conexiones sucesivas, cada una con su chance de fallar.
QUERY=""
while IFS= read -r t; do
    [ -z "${t}" ] && continue
    QUERY="${QUERY}SELECT '${t}' tabla, COUNT(*) filas FROM \`${t}\` UNION ALL "
done <<< "${TABLAS}"
QUERY="${QUERY%UNION ALL }"

CONTEOS="$(sql "${QUERY}" | tr -d '\r')"
[ -n "${CONTEOS}" ] || abortar "no se pudieron contar las filas."

FILAS_TOTAL="$(printf '%s\n' "${CONTEOS}" | awk -F'\t' '{s+=$2} END{print s+0}')"
N_TABLAS="$(printf '%s\n' "${CONTEOS}" | grep -c .)"
ALUMNOS="$(printf '%s\n' "${CONTEOS}" | awk -F'\t' '$1=="alumnos"{print $2}')"
ULTIMA_MIG="$(sql "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1" | tr -d '\r' | head -1)"
N_MIGS="$(sql "SELECT COUNT(*) FROM migrations" | tr -d '\r')"
echo "       ${N_TABLAS} tablas, ${FILAS_TOTAL} filas, ${ALUMNOS} alumnos"

# CHECKSUM TABLE por tabla: con sólo contar filas se puede escapar un dato
# cambiado (llegan 1202 alumnos pero con el curso mal). El checksum compara el
# contenido, y sobre estos 3,4 MB tarda menos de un segundo.
# Sin backticks: CHECKSUM TABLE con nombres entre backticks da error 1103 en
# MySQL 8.4; `db.tabla` a secas funciona y los nombres de este esquema no
# necesitan cotización (son todos identifiers simples).
LISTA_CK=""
while IFS= read -r t; do
    [ -z "${t}" ] && continue
    LISTA_CK="${LISTA_CK}${t},"
done <<< "${TABLAS}"
CHECKSUMS="$(sql "CHECKSUM TABLE ${LISTA_CK%,}" | tr -d '\r' \
    | awk -F'\t' -v pref="${DB_NAME}." '{gsub(pref,"",$1); if ($2=="NULL"||$2=="") print "SIN_CHECKSUM\t"$1; else print $1"\t"$2}')"
N_SIN_CK="$(printf '%s\n' "${CHECKSUMS}" | grep -c '^SIN_CHECKSUM' || true)"

# Archivos subidos: van aparte porque NO están dentro de la base. La tabla
# documentos guarda el nombre del archivo; si la base viaja y los archivos no,
# el módulo Documentos queda con filas apuntando a la nada.
ARCH_MANIFEST=""
HAY_ARCHIVOS=0
for carpeta in "${ARCHIVOS[@]}"; do
    n=0
    b=0
    if [ -d "${STORAGE}/${carpeta}" ]; then
        n="$(find "${STORAGE}/${carpeta}" -type f 2>/dev/null | wc -l | tr -d '[:space:]')"
        b="$(find "${STORAGE}/${carpeta}" -type f -printf '%s\n' 2>/dev/null | awk '{s+=$1} END{print s+0}')"
    fi
    [ "${n}" -gt 0 ] && HAY_ARCHIVOS=1
    ARCH_MANIFEST="${ARCH_MANIFEST}${carpeta}_cantidad=${n}
${carpeta}_bytes=${b}
"
    printf '       %-12s %s archivo(s)\n' "${carpeta}" "${n}"
done

{
    echo "=== MANIFIESTO DE MIGRACIÓN — Sistema ENRO ==="
    echo "generado:        $(date '+%Y-%m-%d %H:%M:%S')"
    echo "origen:          ${ORIGEN_DESC}"
    echo "version mysql:   ${VERSION_MYSQL}"
    echo "base:            ${DB_NAME}"
    echo "tablas:          ${N_TABLAS}"
    echo "filas totales:   ${FILAS_TOTAL}"
    echo "alumnos:         ${ALUMNOS}"
    echo "migraciones:     ${N_MIGS} (ultima: ${ULTIMA_MIG})"
    echo "bd.sql.gz bytes: ${BYTES_BD}"
    echo ""
    echo "[tablas]"
    printf '%s\n' "${CONTEOS}" | awk -F'\t' 'NF>=2{print $1"="$2}'
    echo ""
    echo "[checksums]"
    printf '%s\n' "${CHECKSUMS}" | awk -F'\t' '$1!="SIN_CHECKSUM" && NF>=2{print $1"="$2}'
    if [ "${N_SIN_CK}" -gt 0 ]; then
        echo "# sin checksum para: $(printf '%s\n' "${CHECKSUMS}" | awk -F'\t' '$1=="SIN_CHECKSUM"{printf "%s ", $2}')"
    fi
    echo ""
    echo "[archivos]"
    printf '%s' "${ARCH_MANIFEST}"
} > "${DIR}/MANIFIESTO.txt"

# ==============================================================================
# 5. Archivos + instrucciones + empaquetado
# ==============================================================================
paso 5 "Empaquetando"

# Se archivan relativos a storage/app: del otro lado se destruye dentro de esa
# carpeta y cada archivo cae en su lugar, sin rutas de Windows adentro del tar.
if [ "${HAY_ARCHIVOS}" = 1 ]; then
    INCLUIR=()
    for carpeta in "${ARCHIVOS[@]}"; do
        [ -d "${STORAGE}/${carpeta}" ] && INCLUIR+=("${carpeta}")
    done
    tar -czf "${DIR}/archivos.tgz" -C "${STORAGE}" "${INCLUIR[@]}" \
        || abortar "no se pudieron empaquetar los archivos de ${STORAGE}."
    SHA_AR="$(sha256sum "${DIR}/archivos.tgz" | awk '{print $1}')"
    echo "       archivos.tgz listo"
else
    SHA_AR=""
    echo "       no hay documentos ni fotos que copiar"
fi

# storage/app/backups queda afuera a propósito: son respaldos que la propia app
# genera adentro del contenedor; pesan y son copias de esta misma base.
echo "       (excluido ${STORAGE}/backups: respaldos internos de la app)"

cat > "${DIR}/COMO-RESTAURAR.txt" <<EOF
Cómo pasar los datos al server nuevo
====================================

1) Copiar ESTE archivo .tar.gz al server (una sola cosa, sin carpetas sueltas):

       scp ${SALIDA_DIR}/migracion-ENRO-${MARCA}.tar.gz usuario@server:/tmp/

2) En el server, desde la carpeta del repo (donde está docker-compose.yml) y con
   el sistema ya arriba (bash scripts/iniciar.sh):

       tar -xzf /tmp/migracion-ENRO-${MARCA}.tar.gz -C /tmp
       bash scripts/restaurar-migracion.sh /tmp/${PAQUETE}

   El script verifica el sha256 de cada archivo contra el manifiesto (así sabe
   si la copia viajó íntegra), muestra cuántos datos hay hoy en el destino,
   pide confirmación antes de pisar, importa, restaura los documentos y vuelve a
   contar tabla por tabla para comprobar que llegó lo mismo que salió.

3) Entrar al sistema y contar los alumnos en pantalla: tiene que dar ${ALUMNOS}.

4) Probar descargar un documento. Si el paso de los archivos no corrió, la base
   tiene filas apuntando a archivos que no existen y la descarga da error.

Contenido del paquete
---------------------
Base:       ${DB_NAME} (${N_TABLAS} tablas, ${FILAS_TOTAL} filas, ${ALUMNOS} alumnos)
Migración:  ${ULTIMA_MIG}
Archivos:   $(printf '%s' "${ARCH_MANIFEST}" | grep _cantidad | tr '\n' ' ')

Recordatorio: este paquete tiene datos personales de alumnos. Borrarlo del /tmp
del server y de la notebook apenas termine el traslado.
EOF

SHA_BD="$(sha256sum "${DIR}/bd.sql.gz" | awk '{print $1}')"
{
    echo ""
    echo "[sha256]"
    echo "bd.sql.gz=${SHA_BD}"
    if [ -n "${SHA_AR}" ]; then
        echo "archivos.tgz=${SHA_AR}"
    fi
} >> "${DIR}/MANIFIESTO.txt"

TAR_FINAL="${SALIDA_DIR}/${PAQUETE}.tar.gz"
tar -czf "${TAR_FINAL}" -C "${TMP}" "${PAQUETE}" || abortar "no se pudo crear el paquete final."

SHA_PAQ="$(sha256sum "${TAR_FINAL}" | awk '{print $1}')"
echo ""
echo "=== LISTO ==="
echo "Paquete:  ${TAR_FINAL}"
echo "Tamano:   $(du -h "${TAR_FINAL}" | cut -f1)"
echo "sha256:   ${SHA_PAQ}"
echo ""
echo "Al server se lleva este único archivo. Verificar del otro lado con"
echo "sha256sum el-archivo.tar.gz que el hash coincida antes de restaurar."
echo ""
echo "Contenido: ${ALUMNOS} alumnos, ${N_TABLAS} tablas, ${FILAS_TOTAL} filas."
