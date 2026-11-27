import { defineConfig, devices } from '@playwright/test';

/**
 * Testes E2E do multicálculo (Análises de seguro fiança).
 *
 * Antes de rodar:
 *   1. composer dev                                   (sobe o servidor em 127.0.0.1:8000)
 *   2. php artisan db:seed --class=E2EPermissionsSeeder (uma vez)
 *
 * Rodar:  npm run test:e2e   |   npm run test:e2e:ui   |   npm run test:e2e:report
 */
export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './tests/e2e/.results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['html', { outputFolder: 'tests/e2e/.report', open: 'never' }]],

    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        locale: 'pt-BR',
        trace: 'on',
        screenshot: 'on',
        video: 'retain-on-failure',
    },

    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
        },
    ],

    // Se o `composer dev` já estiver rodando, o Playwright reaproveita o servidor.
    webServer: {
        command: 'php artisan serve',
        url: 'http://127.0.0.1:8000',
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
