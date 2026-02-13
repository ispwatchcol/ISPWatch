import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import Components from 'unplugin-vue-components/vite'
import Icons from 'unplugin-icons/vite'
import IconsResolver from 'unplugin-icons/resolver'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.js'],
      refresh: true,
    }),

    vue(),

    // Auto-import icons and components
    Components({
      dirs: ['resources/js/components'],
      resolvers: [
        IconsResolver({
          prefix: 'icon',
        }),
      ],
    }),

    Icons({
      autoInstall: true,
    }),
  ],

  // ─── Build Optimization ───
  build: {
    // Target modern browsers for smaller output
    target: 'es2020',
    // Chunk splitting for optimal caching
    rollupOptions: {
      output: {
        manualChunks: {
          'vendor-vue': ['vue', 'vue-router', 'pinia'],
          'vendor-supabase': ['@supabase/supabase-js'],
          'vendor-xlsx': ['xlsx'],
        },
      },
    },
    // Raise chunk size warning limit (some components are large)
    chunkSizeWarningLimit: 600,
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: {
      host: 'localhost',
    },
  },
})
