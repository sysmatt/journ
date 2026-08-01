import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { VitePWA } from 'vite-plugin-pwa';

// See docs/spec/operations.md § Deployment model — build output goes to
// webroot/ (committed to git, not built on the server) and that folder
// is what the web server's DocumentRoot points at, separate from api/.
export default defineConfig({
  base: '/',
  publicDir: 'static',
  build: {
    outDir: 'webroot',
    emptyOutDir: true
  },
  plugins: [
    svelte(),
    VitePWA({
      // Never auto-apply an update — see docs/spec/ui-ux.md § Platform.
      // The app surfaces its own "New version available" prompt/button;
      // this only controls how the service worker itself registers.
      registerType: 'prompt',
      includeAssets: ['icons/icon-192.png', 'icons/icon-512.png'],
      manifest: {
        name: 'journ',
        short_name: 'journ',
        description: 'Offline-first incident journaling',
        theme_color: '#12151a',
        background_color: '#12151a',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' }
        ]
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico}']
      }
    })
  ]
});
