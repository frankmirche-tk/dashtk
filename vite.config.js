import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import symfonyPlugin from 'vite-plugin-symfony'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
    const isProd = mode === 'production'

    return {
        plugins: [
            vue(),
            symfonyPlugin(),
        ],

        // ✅ Dev: "/"  |  Prod: "/build/"
        base: isProd ? '/build/' : '/',

        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./assets/src', import.meta.url)),
            },
        },

        server: {
            host: '127.0.0.1',
            port: 5173,
            strictPort: true,

            // ✅ Dev braucht CORS, wenn Symfony über :8000 läuft
            cors: true,

            // ✅ hilft bei manchen Browser/HMR-Konstellationen
            hmr: {
                host: '127.0.0.1',
            },
        },

        build: {
            manifest: true,
            outDir: 'public/build',
            emptyOutDir: true,

            rollupOptions: {
                input: {
                    app: './assets/src/main.js',
                },
            },
        },
    }
})
