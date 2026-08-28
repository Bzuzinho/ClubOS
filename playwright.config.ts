import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: process.env.CI ? 2 : undefined,
    reporter: process.env.CI
        ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
        : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/up',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'firefox-desktop',
            use: { ...devices['Desktop Firefox'] },
        },
        {
            name: 'webkit-desktop',
            use: { ...devices['Desktop Safari'] },
        },
        {
            name: 'chromium-mobile',
            use: { ...devices['Pixel 7'] },
        },
        {
            name: 'webkit-mobile',
            use: { ...devices['iPhone 14'] },
        },
    ],
});
