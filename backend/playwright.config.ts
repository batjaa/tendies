import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  globalSetup: process.env.E2E_SKIP_GLOBAL_SETUP
    ? undefined
    : './e2e/global-setup.ts',
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 2 : 0,
  reporter: [['html', { open: process.env.CI ? 'never' : 'on-failure' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8899',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : {
        command: 'php artisan serve --env=e2e --port=8899',
        port: 8899,
        reuseExistingServer: !process.env.CI,
      },
});
