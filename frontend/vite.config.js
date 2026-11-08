import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { copyFileSync, existsSync, mkdirSync, statSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Los manuales tienen que viajar DENTRO del dist: al colegio se le entrega
// únicamente esa carpeta, así que un PDF que queda en docs/ no se puede
// descargar desde la app en el server. El fuente sigue siendo docs/; acá solo
// se copia y se arma el índice que lee el modal cuando la API no lo tiene.
//
// El manual del programador NO entra: es documentación técnica y un archivo
// dentro del dist es una URL pública para cualquiera. Ese se sirve solo por
// /api/manuales, que es donde el permiso manuales.programador se filtra de
// verdad; si el server no tiene docs/, simplemente no aparece.
const MANUALES = [
  {
    clave: 'usuario',
    archivo: 'Manual-de-usuario-Sistema-EEST.pdf',
    titulo: 'Manual de usuario',
    descripcion: 'Como se usa el sistema pantalla por pantalla: alumnos, cursos, asistencia, notas y documentos.',
  },
]

function manualesEnDist() {
  let root = ''
  let outDir = 'dist'
  return {
    name: 'manuales-en-dist',
    apply: 'build',
    configResolved(config) {
      root = config.root
      outDir = config.build.outDir
    },
    closeBundle() {
      const destino = resolve(root, outDir, 'manuales')
      mkdirSync(destino, { recursive: true })
      const manuales = []
      for (const m of MANUALES) {
        const origen = resolve(root, '..', 'docs', m.archivo)
        if (!existsSync(origen)) {
          this.warn(`docs/${m.archivo} no está: ese manual no viaja en el dist.`)
          continue
        }
        const archivo = resolve(destino, `${m.clave}.pdf`)
        copyFileSync(origen, archivo)
        const { size, mtime } = statSync(archivo)
        manuales.push({
          ...m,
          url: `manuales/${m.clave}.pdf`,
          tamano_kb: Math.round(size / 1024),
          actualizado: new Date(mtime).toISOString().slice(0, 10),
        })
      }
      writeFileSync(resolve(destino, 'indice.json'), JSON.stringify({ manuales }, null, 2) + '\n')
    },
  }
}

// El dist se compila con API="/api" (mismo origen), así que `npm run preview`
// solo puede probarlo si ese /api va al backend; sin esto el login da 404
// porque vite preview no knows de la API. Los mismos prefijos que reenvía
// nginx en el server (ver scripts/servidor-publicacion.js).
const BACKEND_PREVIEW = { target: 'http://127.0.0.1:8000', changeOrigin: true }

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), manualesEnDist()],
  // usePolling: necesario para que HMR detecte cambios cuando src está montado
  // por volumen de Docker en Windows (inotify no cruza el límite del mount).
  server: {
    host: true,
    watch: {
      usePolling: true,
      interval: 300,
    },
  },
  preview: {
    proxy: {
      '/api': BACKEND_PREVIEW,
      '/sanctum': BACKEND_PREVIEW,
      '/storage': BACKEND_PREVIEW,
      '/print': BACKEND_PREVIEW,
      '/calificaciones/planilla': BACKEND_PREVIEW,
      '/horarios/planilla': BACKEND_PREVIEW,
    },
  },
})
