import { defineConfig } from 'vite';

// Builds into the plugin (../assets/game). The output is committed: the live
// server never runs a build. Phaser lives in its own lazy chunk (office.js) so
// the classic view never downloads it.
export default defineConfig({
  base: './',
  publicDir: 'public',
  build: {
    outDir: '../assets/game',
    emptyOutDir: true,
    target: 'es2020',
    cssCodeSplit: false,
    chunkSizeWarningLimit: 1600, // Phaser (lazy, ~350 KB gzip)
    rollupOptions: {
      input: 'src/main.js',
      output: {
        entryFileNames: 'portal.js',
        chunkFileNames: '[name].js',
        assetFileNames: (info) => (info.names?.[0] || '').endsWith('.css') ? 'portal.css' : '[name][extname]',
        manualChunks: (id) => (id.includes('node_modules/phaser') || id.includes('node_modules/easystarjs') || id.includes('/src/office/')) ? 'office' : undefined,
      },
    },
  },
});
