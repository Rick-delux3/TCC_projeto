import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/index.css', 'resources/js/app.js',
                'resources/css/form-register.css',
                'resources/css/header-dashboard-user.css',
                'resources/css/header-dashboard-admin.css',
                'resources/css/dashboard-user.css',
                'resources/css/dashboard-admin.css',
                'resources/js/dashboard-user.js', 'resources/css/simulation.css',
                'resources/css/branding.css',
                'resources/js/simulation.js',
                'resources/css/imobiliarias-admin.css',
                'resources/js/imobiliarias-admin.js',

            ],
            refresh: true,
        }),
    ],
});
