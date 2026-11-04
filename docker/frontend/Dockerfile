# =============================================================================
# Imagen de PRODUCCIÓN del frontend: compila el bundle y lo sirve estático.
#
# Antes de este cambio el contenedor corría `npm run dev` (el dev server de
# Vite, con HMR y transformaciones en caliente) en un entorno productivo:
# lento, con el código fuente a la vista y sin `dist`. Ahora:
#   etapa "build"  -> node compila `dist` con `npm run build`.
#   etapa final    -> nginx:alpine (la MISMA imagen base que el servicio nginx
#                     del docker-compose) sirve el `dist`. No se "instala"
#                     nginx en runtime: es la imagen base, y el compose ya
#                     tiene un nginx, así que no agrega dependencias nuevas.
#
# El acceso público NO es este contenedor: es el servicio nginx del compose
# (puerto 8000), que reenvía las rutas no-API acá y deja /api en PHP-FPM.
# Ver docker/nginx/default.conf.
# =============================================================================

# ---------- Etapa 1: compilación ---------------------------------------------
FROM node:22-alpine AS build
WORKDIR /app

# VITE_API_BASE se "quema" en el bundle en TIEMPO DE BUILD (Vite la sustituye
# en el código), no en tiempo de ejecución: por eso es ARG, no environment del
# servicio. Default "/api" = mismo origen: la app pide la API al host que le
# sirve la página (nginx del compose, puerto 8000) => publicable en la red del
# colegio sin tocar código. Se puede pisar con:
#   docker compose build --build-arg VITE_API_BASE=https://api.escuela.edu.ar/api
ARG VITE_API_BASE=/api
ENV VITE_API_BASE=$VITE_API_BASE

# Se copian SOLO las entradas del build (no `COPY frontend/ .`): así no entra
# el node_modules/ del host (en Windows rompería el build, tiene binarios
# nativos) y la imagen queda chica. `npm ci` usa el lockfile => build repetible.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/index.html frontend/vite.config.js ./
COPY frontend/src ./src
COPY frontend/public ./public
RUN npm run build

# ---------- Etapa 2: servir el estático --------------------------------------
FROM nginx:alpine AS runtime

# Config mínima del SPA. Se genera acá (y no con un archivo montado desde el
# host) para que la imagen sea auto-contenida: el depto. de sistemas solo
# necesita levantar el compose. Reemplaza la config default de la imagen.
#   - `try_files ... /index.html` es el FALLBACK SPA: /alumnos, /login o
#     /dashboard no existen como archivo; se entrega index.html y React Router
#     muestra la vista. Sin esta línea, recargar o compartir un link daría 404.
#   - index.html no se cachea: es el "manifiesto" que referencia los assets
#     recién-hashados; si el navegador lo cachea, la escuela queda en la
#     versión vieja después de publicar.
RUN printf '%s\n' \
  'server {' \
  '    listen 80;' \
  '    server_name _;' \
  '    root /usr/share/nginx/html;' \
  '    index index.html;' \
  '    charset utf-8;' \
  '' \
  '    location / {' \
  '        try_files $uri $uri/ /index.html;' \
  '    }' \
  '' \
  '    location = /index.html {' \
  '        add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0";' \
  '    }' \
  '' \
  '    location = /favicon.ico { access_log off; log_not_found off; }' \
  '    location = /robots.txt  { access_log off; log_not_found off; }' \
  '}' \
  > /etc/nginx/conf.d/default.conf

# dist compilado en la etapa build => adentro de la imagen (inmutable).
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

# nginx en foreground (el dev server de Vite ya no se usa en producción).
CMD ["nginx", "-g", "daemon off;"]
