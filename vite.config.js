import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import symfonyPlugin from 'vite-plugin-symfony'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
    plugins: [
        vue(),
        symfonyPlugin(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./assets/src', import.meta.url)),
        },
    },
    build: {
        rollupOptions: {
            input: {
                app: './assets/src/main.js',
            },
        },
    },
})
