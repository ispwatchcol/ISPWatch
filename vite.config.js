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

    // 👇 Auto-import de íconos y componentes
    Components({
      resolvers: [
        IconsResolver({
          prefix: 'icon', // podrás usar icon-fa-solid-user, icon-mdi-home, etc.
        }),
      ],
    }),

    Icons({
      autoInstall: true, // instala los íconos automáticamente
    }),
  ],

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: {
      host: 'localhost', // For local development, use localhost for HMR
    },
  },
})

