import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  // Caminhos relativos no build: o app abre também a partir de uma subpasta.
  base: './',
  plugins: [vue()],
  resolve: {
    // @/ aponta para src/ (ex.: '@/js/data/model').
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: { port: 5174, strictPort: true },
  // pdfmake e suas fontes (~1,4 MB e ~0,9 MB) ficam em chunks próprios, baixados só ao gerar um relatório.
  build: { chunkSizeWarningLimit: 1500 },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.js'],
    restoreMocks: true,
  },
})
