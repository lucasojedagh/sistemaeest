#!/bin/sh
# OBSOLETO — no se usa en la publicación. Era el arranque del contenedor cuando
# el frontend corría el dev server de Vite (npm run dev). El Dockerfile de esta
# carpeta ahora compila el bundle en una etapa node y lo sirve nginx en la
# imagen final, y ni copia ni referencia este archivo; docker-compose.yml tampoco
# define entrypoint para ese servicio. Se dejó acá solo por si algún día hace
# falta el dev server adentro de un contenedor. En producción no usarlo: el dev
# server no compila `dist`, deja el código fuente a la vista y atiende de a poco.
set -e

cd /app

if [ ! -d "node_modules" ]; then
    echo "Instalando dependencias del frontend..."
    npm install
fi

echo "Iniciando Vite dev server..."
exec npm run dev -- --host 0.0.0.0
