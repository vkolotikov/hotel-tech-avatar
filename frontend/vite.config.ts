import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const basePath = env.VITE_BASE_PATH || '/hotel-tech/apps/avatar/frontend/dist/';

  return {
    base: basePath,
    plugins: [
      react(),
      VitePWA({
        registerType: 'autoUpdate',
        includeAssets: ['pwa/apple-touch-icon.png'],
        manifest: {
          name: 'AvatarHub',
          short_name: 'AvatarHub',
          description: 'Visual multi-agent business assistant',
          start_url: basePath,
          scope: basePath,
          display: 'standalone',
          theme_color: '#0b1222',
          background_color: '#0b1222',
          icons: [
            { src: '/pwa/icon-192.png', sizes: '192x192', type: 'image/png' },
            { src: '/pwa/icon-512.png', sizes: '512x512', type: 'image/png' },
            { src: '/pwa/icon-maskable-192.png', sizes: '192x192', type: 'image/png', purpose: 'maskable' },
            { src: '/pwa/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
            { src: '/pwa/apple-touch-icon.png', sizes: '180x180', type: 'image/png' },
          ],
        },
        workbox: {
          cleanupOutdatedCaches: true,
          sourcemap: false,
          runtimeCaching: [
            {
              urlPattern: ({ url }) => url.pathname.startsWith('/api/'),
              handler: 'NetworkOnly',
              method: 'GET',
            },
            {
              urlPattern: ({ url }) => url.pathname.startsWith('/assets/'),
              handler: 'NetworkOnly',
            },
          ],
        },
      }),
    ],
    build: {
      // Keep backend media proxy on /assets in dev, while avoiding clashes
      // with Vite bundle files when testing preview/PWA builds.
      assetsDir: 'app-assets',
    },
    server: {
      proxy: {
        '/api': { target: 'http://avatar.local', changeOrigin: true },
        '/assets': { target: 'http://avatar.local', changeOrigin: true },
      },
    },
  };
});
