import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import symfonyPlugin from 'vite-plugin-symfony'

export default defineConfig({
    plugins: [
        vue(),
        symfonyPlugin(),   // <- sorgt für /build, manifest, dev-integration etc.
    ],
    resolve: {
        alias: {
            '@': '/assets/src', // optional: Komfort-Alias für deine Imports
        },
    },
    build: {
        rollupOptions: {
            // WICHTIG: benannte Entries definieren (keine Dateipfade in Twig!)
            input: {
                app: './assets/src/main.js',
                // optional: zusätzliches globales CSS, verhindert FOUC
                // styles: './assets/styles.css',
            },
        },
    },
})
