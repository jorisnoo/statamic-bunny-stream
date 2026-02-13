import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    build: {
        target: 'es2021',
    },
    plugins: [
        laravel({
            input: [
                'resources/js/addon.js',
                'resources/css/addon.css',
            ],
            publicDirectory: 'resources/dist',
        }),
        statamic(),
        tailwindcss(),
    ],
});
