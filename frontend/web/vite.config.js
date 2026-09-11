import { defineConfig } from 'vite'
import { resolve } from 'node:path'

export default defineConfig({
  base: '/build/web/',
  server: {
    host: '127.0.0.1',
    port: 5174,
    cors: true,
  },
  test: {
    environment: 'jsdom',
  },
  build: {
    outDir: resolve(process.cwd(), '../../public/build/web'),
    emptyOutDir: true,
    manifest: 'manifest.json',
    rolldownOptions: {
      input: resolve(process.cwd(), 'src/js/main.js'),
    },
  },
})
