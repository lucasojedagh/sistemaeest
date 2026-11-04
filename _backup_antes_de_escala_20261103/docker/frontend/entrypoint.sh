#!/bin/sh
set -e

cd /app

if [ ! -d "node_modules" ]; then
    echo "Instalando dependencias del frontend..."
    npm install
fi

echo "Iniciando Vite dev server..."
exec npm run dev -- --host 0.0.0.0
