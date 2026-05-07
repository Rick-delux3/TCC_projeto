import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 
                'resources/css/form-register.css', 'resources/css/welcome.css',
                'resources/css/dashboard-user.css',
                'resources/js/welcome.js', 'resources/css/simulation.css',
                'resources/js/simulation.js',

            ],
            refresh: true,
        }),
    ],
});
