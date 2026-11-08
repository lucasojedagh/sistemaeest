#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/restaurar-migracion.sh
# Propósito: del lado del server NUEVO, tomar el paquete que generó
#            scripts/exportar-migracion.sh y dejar la base de datos y los
#            archivos subidos exactamente como estaban en el otro equipo.
#
# Uso:  bash scripts/restaurar-migracion.sh /tmp/migracion-ENRO-AAAA-MM-DD-HHMM
#       bash scripts/restaurar-migracion.sh <paquete> --forzar      # pisa sin preguntar
#       bash scripts/restaurar-migracion.sh <paquete> --verificar    # sólo compara, no escribe
#       bash scripts/restaurar-migracion.sh <paquete> --sin-archivos # saltea documentos/fotos
#
# Variables que ajustan el destino (rara vez hace falta):
#   ENRO_DB_NAME       base destino    (default sistema_escuela)
#   ENRO_STORAGE_DIR   dónde van documentos y fotos (default backend/storage/app)
#
# Antes de tocar nada verifica el sha256 de cada archivo del paquete contra el
# manifiesto (una copia por red truncada es el clásico) y muestra cuántos datos
# hay hoy en el destino; si hay, pide confirmación y deja un respaldo.
# ==============================================================================

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

DB_NAME="${ENRO_DB_NAME:-sistema_escuela}"
DB_USER="${ENRO_DB_USER:-root}"
DB_PASS="${ENRO_DB_PASS:-}"
DB_HOST="${ENRO_DB_HOST:-127.0.0.1}"
DB_PORT="${ENRO_DB_PORT:-3306}"
STORAGE_DIR="${ENRO_STORAGE_DIR:-backend/storage/app}"
BACKUP_DIR="backups"
TOTAL_PASOS=6

MODO=""
PAQUETE=""
FORZAR=0
SOLO_VERIFICAR=0
SIN_ARCHIVOS=0

for arg in "$@"; do
    case "${arg}" in
        --docker)       MODO=docker ;;
        --local)        MODO=local ;;
        --forzar)       FORZAR=1 ;;
        --verificar)    SOLO_VERIFICAR=1 ;;
        --sin-archivos) SIN_ARCHIVOS=1 ;;
        -h|--help)
            sed -n '3,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        -*)
            echo "Opción desconocida: ${arg}" >&2
            exit 2 ;;
        *)
            PAQUETE="${arg%/}" ;;
    esac
done

paso() { echo "[${1}/${TOTAL_PASOS}] ${2}"; }
abortar() { echo "ERROR: $*" >&2; exit 1; }

[ -n "${PAQUETE}" ] || abortar "falta decir dónde está el paquete.
   Uso: bash scripts/restaurar-migracion.sh /ruta/migracion-ENRO-AAAA-MM-DD-HHMM"
[ -d "${PAQUETE}" ] || abortar "no existe la carpeta '${PAQUETE}'. Si todavía tenés el .tar.gz, descomprimilo primero:
   tar -xzf migracion-ENRO-....tar.gz -C /tmp"
MANIFIESTO="${PAQUETE}/MANIFIESTO.txt"
DUMP="${PAQUETE}/bd.sql.gz"
[ -f "${MANIFIESTO}" ] || abortar "no encuentro ${MANIFIESTO}: esto no es un paquete de migración."
[ -f "${DUMP}" ] || abortar "no encuentro ${DUMP} dentro del paquete."
gzip -t "${DUMP}" 2>/dev/null || abortar "${DUMP} está dañado: gzip no lo puede leer. Volvé a copiar el paquete."

# Un paquete no se toca mientras no se pueda leer: sin estas claves el script
# no puede comparar nada del otro lado.
sin_comillas() { tr -d '\r' | head -1 | sed 's/[`"\\;]//g'; }
campo_manifest() {
    # Lee las líneas "clave:   valor" del encabezado. Se corta por el primer ":",
    # no por espacios: hay claves con espacio adentro ("version mysql") y, con
    # el separador de awk por defecto, el campo uno llega con los dos puntos
    # pegados ("migraciones:") y la comparación nunca matchea. Pasó acá: el
    # control de versiones se saltaba en silencio sin avisar.
    awk -v k="$1" '{
        i = index($0, ":")
        if (i > 0) {
            clave = substr($0, 1, i - 1)
            valor = substr($0, i + 1)
            gsub(/[ \t\r]+$/, "", clave)
            gsub(/^[ \t]+/, "", valor)
            gsub(/[ \r]+$/, "", valor)
            if (clave == k) { print valor; exit }
        }
    }' "${MANIFIESTO}"
}

# ==============================================================================
# 1. Destino
# ==============================================================================
paso 1 "Identificando el destino"

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

if command -v mysql >/dev/null 2>&1; then
    MYSQL_BIN="mysql"
    DUMP_BIN="mysqldump"
elif BIN_LARAGON="$(carpeta_bin_laragon)" && [ -n "${BIN_LARAGON}" ]; then
    MYSQL_BIN="${BIN_LARAGON}/mysql.exe"
    DUMP_BIN="${BIN_LARAGON}/mysqldump.exe"
else
    MYSQL_BIN=""
    DUMP_BIN=""
fi

HAY_DOCKER=0
if docker_arriba; then
    if docker compose -f docker-compose.yml exec -T mysql sh -c \
        'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e "SELECT 1" >/dev/null' 2>/dev/null; then
        HAY_DOCKER=1
    fi
fi

HAY_LOCAL=0
if [ -n "${MYSQL_BIN}" ]; then
    if MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
        -N -B -e "SELECT 1" >/dev/null 2>&1; then
        HAY_LOCAL=1
    fi
fi

if [ -z "${MODO}" ]; then
    if [ "${HAY_DOCKER}" = 1 ] && [ "${HAY_LOCAL}" = 1 ]; then
        abortar "hay un contenedor enro-mysql arriba y además responde MySQL en ${DB_HOST}:${DB_PORT}.
       Decidir dónde querés restaurar: --docker (lo normal en el server) o --local."
    fi
    if [ "${HAY_DOCKER}" = 1 ]; then
        MODO=docker
    elif [ "${HAY_LOCAL}" = 1 ]; then
        MODO=local
    else
        abortar "no hay dónde restaurar: ni contenedor enro-mysql arriba ni MySQL alcanzable en ${DB_HOST}:${DB_PORT}.
       En el server, primero levantar el sistema (bash scripts/iniciar.sh) y después correr este script."
    fi
fi

if [ "${MODO}" = "docker" ]; then
    docker_arriba || abortar "pediste --docker pero el contenedor enro-mysql no está corriendo."
    echo "       Destino: contenedor enro-mysql, base '${DB_NAME}'"
else
    echo "       Destino: MySQL local en ${DB_HOST}:${DB_PORT}, base '${DB_NAME}'"
fi

# Consultas de una línea, sin mostrar la base (usa el contexto).
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

# Sentencias sin base de contexto (crear/borrar) y, en la importación, con la
# base como último argumento y el SQL entrando por stdin.
sql_global() {
    if [ "${MODO}" = "docker" ]; then
        docker compose -f docker-compose.yml exec -T mysql sh -c \
            'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"' sh "${@}"
    else
        MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" -h "${DB_HOST}" -P "${DB_PORT}" \
            -u "${DB_USER}" "${@}"
    fi
}

# La versión importa: un dump de 8.4 restaurado en MySQL 8.0 anda (las tablas de
# este sistema usan utf8mb4_unicode_ci, no los collations nuevos), pero si el
# destino es más viejo que el origen la importación puede morir a mitad, con la
# base ya destruida. Mejor saberlo antes.
VERSION_SRC="$(campo_manifest 'version mysql')"
VERSION_DST="$(sql_global -N -B -e 'SELECT VERSION()' | sin_comillas)"
mayor_de() { [ "$(printf '%s' "$1" | cut -d. -f1)" -ge "$(printf '%s' "$2" | cut -d. -f1)" ]; }
if [ -n "${VERSION_SRC}" ] && [ -n "${VERSION_DST}" ]; then
    if ! mayor_de "${VERSION_DST}" "${VERSION_SRC}"; then
        abortar "el origen es MySQL ${VERSION_SRC} y el destino es MySQL ${VERSION_DST}.
       Restaurar un volcado de una versión más nueva en una más vieja puede fallar a
       mitad, con la base destino ya destruida. Subí la versión del destino o llevá
       el paquete a un MySQL ${VERSION_SRC%%.*} o superior."
    fi
    echo "       Versiones: origen ${VERSION_SRC} -> destino ${VERSION_DST} (compatible)"
fi

# ==============================================================================
# 2. Integridad del paquete
# ==============================================================================
paso 2 "Verificando que el paquete llegó íntegro"

sha_manifest() {
    awk -v k="$1" 'BEGIN{sec=""} /^\[/{sec=$0; next}
        sec=="[sha256]" && index($0, k"=")==1 {print substr($0, length(k)+2); exit}' "${MANIFIESTO}"
}

valor_manifest() {
    awk -v k="$1" 'BEGIN{sec=""} /^\[/{sec=$0; next}
        (sec=="[tablas]" || sec=="[archivos]") && index($0, k"=")==1 {print substr($0, length(k)+2); exit}' \
        "${MANIFIESTO}"
}

fallas=0
for archivo in bd.sql.gz archivos.tgz; do
    esperado="$(sha_manifest "${archivo}")"
    [ -z "${esperado}" ] && continue
    if [ ! -f "${PAQUETE}/${archivo}" ]; then
        echo "   FALTA ${archivo}, y el manifiesto lo anuncia"
        fallas=$((fallas + 1))
        continue
    fi
    real="$(sha256sum "${PAQUETE}/${archivo}" | awk '{print $1}')"
    if [ "${real}" != "${esperado}" ]; then
        echo "   DISTINTO ${archivo}: el sha256 no coincide con el del manifiesto"
        fallas=$((fallas + 1))
    else
        echo "   OK ${archivo}"
    fi
done
if [ "${fallas}" -ne 0 ]; then
    abortar "el paquete llegó incompleto o mal copiado. Volver a pasarlo del otro equipo y controlar el sha256 del .tar.gz antes de descomprimir."
fi

ALUMNOS_ESPERADOS="$(valor_manifest alumnos)"
ALUMNOS_ESPERADOS="${ALUMNOS_ESPERADOS:-0}"
TABLAS_ESPERADAS="$(awk 'BEGIN{sec=""} /^\[/{sec=$0; next} sec=="[tablas]" && NF{c++} END{print c+0}' "${MANIFIESTO}")"
ULTIMA_MIG_ESPERADA="$(campo_manifest 'migraciones')"
ULTIMA_MIG_ESPERADA="$(printf '%s' "${ULTIMA_MIG_ESPERADA}" | sed 's/.*ultima: //; s/)//')"
echo "       ${TABLAS_ESPERADAS} tablas, ${ALUMNOS_ESPERADOS} alumnos, migración ${ULTIMA_MIG_ESPERADA}"

# ==============================================================================
# 3. Estado del destino (y respaldo si ya tiene datos)
# ==============================================================================
paso 3 "Mirando qué hay hoy en el destino"

# information_schema responde aunque la base no exista: evita el if-feo de
# "SHOW DATABASES LIKE" con comillas escapadas a través de docker exec.
TABLAS_ACTUALES="$(sql_global -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'" | sin_comillas)"
TABLAS_ACTUALES="${TABLAS_ACTUALES:-0}"

ALUMNOS_ACTUALES=0
if [ "${TABLAS_ACTUALES}" -gt 0 ]; then
    ALUMNOS_ACTUALES="$(sql "SELECT COUNT(*) FROM alumnos" 2>/dev/null | sin_comillas || true)"
    ALUMNOS_ACTUALES="${ALUMNOS_ACTUALES:-0}"
fi
echo "       '${DB_NAME}': ${TABLAS_ACTUALES} tabla(s), ${ALUMNOS_ACTUALES} alumno(s)"

if [ "${SOLO_VERIFICAR}" = 1 ]; then
    [ "${TABLAS_ACTUALES}" -gt 0 ] || abortar "no hay nada que verificar: la base '${DB_NAME}' no existe o está vacía."
elif [ "${ALUMNOS_ACTUALES}" -gt 0 ]; then
    echo ""
    echo "   ATENCIÓN: el destino ya tiene ${ALUMNOS_ACTUALES} alumno(s) cargados."
    echo "   Restaurar los reemplaza por los ${ALUMNOS_ESPERADOS} del paquete."
    echo ""
    if [ "${FORZAR}" -ne 1 ]; then
        printf "   Para pisar esa base escribí PISAR (cualquier otra cosa cancela): "
        read -r respuesta
        if [ "${respuesta:-}" != "PISAR" ]; then
            abortar "cancelado. No se tocó nada."
        fi
    fi
    echo "   Dejo un respaldo del destino antes de tocar:"
    mkdir -p "${BACKUP_DIR}"
    ANTES="${BACKUP_DIR}/antes-de-restaurar-$(date +%Y-%m-%d-%H%M%S).sql.gz"
    if [ "${MODO}" = "docker" ]; then
        docker compose -f docker-compose.yml exec -T mysql sh -c \
            'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$1"' \
            sh "${DB_NAME}" | gzip > "${ANTES}"
    else
        MYSQL_PWD="${DB_PASS}" "${DUMP_BIN}" -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
            --single-transaction --routines --triggers "${DB_NAME}" | gzip > "${ANTES}"
    fi
    if [ ! -s "${ANTES}" ]; then
        rm -f "${ANTES}"
        abortar "no pude generar el respaldo del destino. Sin respaldo a mano no piso nada."
    fi
    echo "   -> ${ANTES}"
    echo "      (si algo sale mal, con ese archivo volvés atrás: gunzip -c antes-de-restaurar-*.sql.gz | mysql ${DB_NAME})"
fi

# ==============================================================================
# 4. Importar
# ==============================================================================
paso 4 "Importando la base de datos"

if [ "${SOLO_VERIFICAR}" = 1 ]; then
    echo "       (--verificar: no escribo nada)"
else
    # La base destino se recrea vacía. El dump ya trae DROP TABLE por tabla,
    # pero si en el destino quedara alguna tabla que en el origen ya no existe,
    # quedaría viva y desincronizada; recrear es lo único predecible, y por eso
    # el paso 3 exige respaldo antes de pisar.
    if [ "${TABLAS_ACTUALES}" -gt 0 ]; then
        sql_global -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;"
    fi
    sql_global -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    INICIO="$(date +%s)"
    # El gzip corre en el host y mysql recibe los bytes por stdin, así la
    # restauración no depende de que la imagen de MySQL tenga gzip instalado.
    # Con `set -o pipefail`, si mysql se cae a mitad, el pipe devuelve error y
    # el script aborta en vez de felicitar la importación.
    if ! gunzip -c "${DUMP}" | sql_global --default-character-set=utf8mb4 "${DB_NAME}"; then
        abortar "mysql devolvió error durante la importación. La base quedó a medio cargar.
       Volvé al respaldo del paso 3 (o, si el destino estaba vacío, borrá '${DB_NAME}' y empezá de nuevo)."
    fi
    echo "       importado en $(( $(date +%s) - INICIO ))s"
fi

# ==============================================================================
# 5. Archivos subidos (documentos y fotos de perfil)
# ==============================================================================
paso 5 "Restaurando los archivos subidos"

if [ "${SOLO_VERIFICAR}" = 1 ]; then
    echo "       (--verificar: no escribo nada)"
elif [ "${SIN_ARCHIVOS}" = 1 ]; then
    echo "       (--sin-archivos: salto este paso por pedido explícito)"
elif [ -f "${PAQUETE}/archivos.tgz" ]; then
    mkdir -p "${STORAGE_DIR}"
    if ! tar -xzf "${PAQUETE}/archivos.tgz" -C "${STORAGE_DIR}"; then
        abortar "no pude desempaquetar archivos.tgz en ${STORAGE_DIR}."
    fi
    echo "       destruido en ${STORAGE_DIR}/"

    if [ "${MODO}" = "docker" ]; then
        # ./backend va montado desde la carpeta del repo, o sea que lo que escribió
        # el host es lo que ve el contenedor. Pero el que guarda un documento nuevo
        # adentro es www-data: si los archivos quedaron del usuario del host, la
        # próxima subida puede fallar por permiso. Se ajusta desde adentro, usando
        # el www-data del propio contenedor (php:8.2-fpm ejecuta como root).
        docker compose -f docker-compose.yml exec -T backend sh -c \
            'chown -R www-data:www-data /var/www/storage/app/documentos /var/www/storage/app/avatars 2>/dev/null; \
             chmod -R u+rwX,go+rX /var/www/storage/app/documentos /var/www/storage/app/avatars' \
            || echo "       AVISO: no pude ajustar permisos desde el contenedor."
    fi

    esperado_doc="$(valor_manifest documentos_cantidad)"
    real_doc="$(find "${STORAGE_DIR}/documentos" -type f 2>/dev/null | wc -l | tr -d '[:space:]')"
    if [ -n "${esperado_doc}" ] && [ "${esperado_doc}" != "${real_doc}" ]; then
        echo "       documentos: ${real_doc} archivo(s), pero el manifiesto anunciaba ${esperado_doc} -> REVISAR"
    else
        echo "       documentos: ${real_doc} archivo(s), coincidiendo con el manifiesto"
    fi
else
    echo "       el paquete no trae archivos.tgz (en el origen no había documentos ni fotos)"
fi

# ==============================================================================
# 6. Verificación final, tabla por tabla
# ==============================================================================
paso 6 "Verificando fila por fila contra el manifiesto"

# Tablas que existen en el destino ahora: se cuentan sólo ésas y las que faltan
# se reportan aparte (una UNION ALL con una tabla inexistente revienta toda la
# consulta, y eso taparía el diagnóstico).
EXISTEN_DST="$(sql_global -N -B -e \
    "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB_NAME}'" \
    | tr -d '\r' | awk 'NF{print $1}')"
es_tabla_destino() {
    printf '%s\n' "${EXISTEN_DST}" | grep -qx "$1"
}

QUERY=""
FALTANTES=""
while IFS= read -r t; do
    case "${t}" in
        *[!A-Za-z0-9_]*) continue ;;
    esac
    [ -z "${t}" ] && continue
    if es_tabla_destino "${t}"; then
        QUERY="${QUERY}SELECT '${t}' tabla, COUNT(*) filas FROM \`${t}\` UNION ALL "
    else
        FALTANTES="${FALTANTES}${t}
"
    fi
done < <(awk 'BEGIN{sec=0} /^\[/{sec=($0=="[tablas]"); next} sec && NF{split($0,p,"="); print p[1]}' "${MANIFIESTO}")
QUERY="${QUERY%UNION ALL }"

if [ -z "${QUERY}" ]; then
    abortar "no pude armar la consulta de verificación (¿el manifiesto está truncado?)."
fi

CONTEOS="$(sql "${QUERY}" | tr -d '\r')"
[ -n "${CONTEOS}" ] || abortar "no pude volver a contar las tablas del destino."

# Segundo nivel de control: el checksum compara el contenido, no la cantidad.
# Dos filas pueden cuadrar y aun así venir un apellido cambiado. Se hace sólo
# sobre las tablas que existen, y si el paquete no trae [checksums] (paquetes
# viejos) se compara lo que se puede: las filas.
LISTA_CK=""
while IFS= read -r t; do
    case "${t}" in *[!A-Za-z0-9_]*) continue ;; esac
    [ -z "${t}" ] && continue
    LISTA_CK="${LISTA_CK}${t},"
done <<< "${EXISTEN_DST}"
LISTA_CK="${LISTA_CK%,}"
CHECKSUMS_DST=""
if [ -n "${LISTA_CK}" ]; then
    CHECKSUMS_DST="$(sql "CHECKSUM TABLE ${LISTA_CK}" 2>/dev/null | tr -d '\r' \
        | awk -F'\t' -v p="${DB_NAME}." '{gsub(p, "", $1); print $1"\t"$2}')"
fi
ck_manifest() {
    awk -v k="$1" 'BEGIN{sec=""} /^\[/{sec=$0; next}
        sec=="[checksums]" && index($0, k"=")==1 {print substr($0, length(k)+2); exit}' "${MANIFIESTO}"
}
ck_destino() {
    printf '%s\n' "${CHECKSUMS_DST}" | awk -F'\t' -v k="$1" '$1==k{print $2; exit}'
}
N_HAY_CK_MANIFEST="$(awk 'BEGIN{sec=0} /^\[/{sec=($0=="[checksums]"); next} sec && NF{c++} END{print c+0}' "${MANIFIESTO}")"
CK_OK=0

# El bucle escribe directo en el terminal (no dentro de $( )): si no, el
# contador de diferencias se incrementa en un subshell y no llega abajo.
DISTINTAS=0
while IFS=$'\t' read -r t n; do
    [ -z "${t}" ] && continue
    esperado="$(valor_manifest "${t}")"
    estado="ok"
    if [ "${esperado}" != "${n}" ]; then
        estado="DISTINTO: el paquete decía ${esperado} fila(s)"
        DISTINTAS=$((DISTINTAS + 1))
    elif [ "${N_HAY_CK_MANIFEST}" -gt 0 ]; then
        ck_e="$(ck_manifest "${t}")"
        ck_r="$(ck_destino "${t}")"
        if [ -z "${ck_e}" ] || [ -z "${ck_r}" ]; then
            estado="ok (filas; sin checksum comparable)"
        elif [ "${ck_e}" != "${ck_r}" ]; then
            estado="DISTINTO: misma cantidad de filas pero CONTENIDO distinto"
            DISTINTAS=$((DISTINTAS + 1))
        else
            estado="ok (filas y contenido)"
            CK_OK=$((CK_OK + 1))
        fi
    fi
    printf '   %-32s %8s  %s\n' "${t}" "${n}" "${estado}"
done <<< "${CONTEOS}"

if [ -n "${FALTANTES}" ]; then
    while IFS= read -r t; do
        [ -z "${t}" ] && continue
        printf '   %-32s %8s  %s\n' "${t}" "-" "NO EXISTE en el destino"
        DISTINTAS=$((DISTINTAS + 1))
    done <<< "${FALTANTES}"
fi

ALUMNOS_FINALES="$(sql "SELECT COUNT(*) FROM alumnos" | sin_comillas)"

echo ""
if [ "${DISTINTAS}" -ne 0 ]; then
    echo "=== ALERTA: ${DISTINTAS} tabla(s) no coinciden con el manifiesto ==="
    echo "    No sigas usando el sistema hasta resolverlo."
    if [ -n "${ANTES:-}" ]; then
        echo "    Tenés el respaldo del destino en ${ANTES}"
    fi
    exit 1
fi

# La caché de vistas, divisiones y parámetros se quedó con los datos viejos: si
# no se tira, las pantallas muestran el server anterior aunque la base sea nueva.
if [ "${MODO}" = "docker" ] && [ "${SOLO_VERIFICAR}" != 1 ]; then
    if ! docker compose -f docker-compose.yml exec -T backend php artisan cache:clear >/dev/null 2>&1; then
        echo "AVISO: no pude limpiar la caché. Correr a mano: docker compose exec backend php artisan cache:clear"
    fi
fi

echo "=== MIGRACIÓN OK ==="
echo "Base:      ${DB_NAME} (${TABLAS_ESPERADAS} tablas verificadas una por una, todas coincidentes)"
echo "Alumnos:   ${ALUMNOS_FINALES} (en el origen había ${ALUMNOS_ESPERADOS})"
if [ "${N_HAY_CK_MANIFEST}" -gt 0 ]; then
    echo "Contenido: checksum idéntico en ${CK_OK} de ${TABLAS_ESPERADAS} tablas"
else
    echo "Contenido: este paquete no trae checksums, se compararon sólo las filas"
fi
if [ "${SOLO_VERIFICAR}" = 1 ]; then
    echo "Modo:      --verificar (no se escribió nada)"
fi
echo ""
echo "Faltan dos cosas, y son a mano:"
echo "  1) Entrar al sistema en el navegador y revisar el padrón, un horario y la"
echo "     descarga de un documento (los archivos viajan en archivos.tgz)."
echo "  2) Borrar el paquete del server cuando termine el traslado, que tiene datos"
echo "     personales de los alumnos:"
echo "       rm -rf '${PAQUETE}'"
