import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // usePolling: necesario para que HMR detecte cambios cuando src está montado
  // por volumen de Docker en Windows (inotify no cruza el límite del mount).
  server: {
    host: true,
    watch: {
      usePolling: true,
      interval: 300,
    },
  },
})
