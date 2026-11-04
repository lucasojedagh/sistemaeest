# Frontend — Sistema ENRO

React 19 + Vite. Sin TypeScript. CSS puro por módulos (sin Tailwind).

## Cómo correr

```bash
docker compose up -d          # en la raíz del repo; frontend en http://localhost:5173
npm run lint                  # dentro de frontend/
npm run build                 # build de producción
```

> `vite.config.js` usa `server.watch.usePolling` para que el hot-reload funcione
> con el volumen montado de Docker en Windows.

## Estructura

```
src/
├── api/                  # Endpoints agrupados por dominio (usan lib/api)
│   ├── auth.js           #   login, registro, usuarios admin, roles
│   └── asistencia.js     #   asistenciaApi: cursos, clases, justificaciones...
├── auth/                 # Autenticación y autorización
│   ├── AuthContext.jsx   #   Estado global de sesión (useAuth)
│   ├── guards.jsx        #   RequireAuth / RequireGuest / RequireRole
│   ├── roles.js          #   Constantes de roles
│   └── confirmPasswordPrompt.js
├── components/
│   ├── Alumnos/          # Componentes SOLO de alumnos (+ sus CSS junto a cada uno)
│   ├── Asistencia/
│   ├── Calificaciones/
│   ├── Curso/            # Modales de cursos: materias, horarios, promoción...
│   ├── Common/           # UI reutilizable: Breadcrumbs, Toast, Skeleton, FichaLayout...
│   ├── Preceptores/
│   ├── Profesores/
│   ├── layout/           # Navbar (sidebar)
│   └── modals/           # Modales globales de app: Account, About, Register, ConfirmAction
├── hooks/                # useConfirmAction
├── lib/                  # Utilidades base (SIN React salvo indicación)
│   ├── api.js            # ★ CAPA ÚNICA DE RED: apiGet/apiPost/apiPut/apiDelete/apiUpload/apiBlob
│   ├── authToken.js      #   Token JWT en localStorage + headers
│   ├── apiGate.js        #   Gate con límite de concurrencia (solo Calificaciones)
│   ├── pageCache.js      #   Cache de páginas con TTL 1h (memoria + localStorage)
│   ├── cursos.js         #   Helpers de año/división (parseCurso, cursoLabel...)
│   └── asistencia.js     #   Labels/formatos de asistencia
├── pages/                # Una carpeta plana: un archivo por ruta (los importa App.jsx)
├── styles/               # CSS global: variables.css, base.css y CSS de páginas
└── utils/export.js       # Exportar a CSV/PDF
```

## Reglas del proyecto

1. **Toda petición HTTP pasa por `src/lib/api.js`.** Nunca uses `fetch` ni axios
   directo en componentes. Los errores lanzan `Error` con el `message` del backend
   y adjuntan `err.status` y `err.data`.
2. **Endpoints de dominio nuevos** → función exportada en `src/api/<dominio>.js`
   que internamente llama a `lib/api`.
3. **Componente nuevo**: si es de un módulo va en `components/<Módulo>/`; si es UI
   genérica va en `components/Common/`; si es un modal global, `components/modals/`.
4. **CSS**: cada componente importa su propio `.css` ubicado junto a él. El CSS de
   páginas vive en `styles/`. Variables de diseño en `styles/variables.css`.
5. **Cacheo de datos de página**: usar `getCached/setCache` de `lib/pageCache.js`
   (TTL 1 hora). Patrón de referencia: apertura instantánea de materias en
   `pages/Cursos.jsx` (`abrirModalMateriasGlobal`).
6. **Rutas nuevas** → página en `pages/` + `<Route>` en `App.jsx` envuelta en los
   guards correspondientes.
7. **Acciones sensibles** requieren `_detalles` (justificación) y confirmación de
   contraseña vía `auth/confirmPasswordPrompt.js`.

## Notas

- El rol `jefe_preceptores` tiene su ruta (`/jefe-preceptores`) que reutiliza la
  página Preceptores con otro scope.
- La cache del backend de `/materias/todas` se invalida desde los controllers al
  editar materias; el frontend además cachea por año con TTL propio.
