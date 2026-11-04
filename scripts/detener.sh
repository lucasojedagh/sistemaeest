#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/detener.sh
# Propósito: apagar el Sistema ENRO en el server Linux. Equivalente de
#            scripts/detener.ps1.
# Uso:  bash scripts/detener.sh        # deja los contenedores detenidos y el
#                                      dato intacto (se relanza con iniciar.sh)
#       bash scripts/detener.sh --volumenes
#                                      # APAGA Y BORRA el volumen mysql_data: se
#                                      va la base de datos. Solo con respaldo hecho.
# ==============================================================================

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ "${1:-}" = "--volumenes" ]; then
    echo "Esto BORRA la base de datos (volumen mysql_data). No hay deshacer."
    read -r -p "¿Seguro? Escribí SI: " confirmacion
    if [ "$confirmacion" != "SI" ]; then
        echo "Cancelado; la base sigue intacta."
        exit 0
    fi
    echo "Deteniendo y eliminando volúmenes..."
    docker compose down -v
    echo "Contenedores detenidos y volumen de datos borrado."
else
    echo "Deteniendo contenedores (los datos quedan en el volumen mysql_data)..."
    docker compose down
    echo "Contenedores detenidos. Volver a levantar: bash scripts/iniciar.sh"
fi
