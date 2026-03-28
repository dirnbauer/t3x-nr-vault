import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './Tests/E2E',
  fullyParallel: false, // Tests within a file run sequentially (safer for state)
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 4 : undefined, // Parallel by spec file; undefined = half of CPUs locally
  reporter: [
    ['html', { outputFolder: '.Build/playwright-report' }],
    ['list'],
  ],
  outputDir: '.Build/playwright-results',
  use: {
    baseURL: process.env.TYPO3_BASE_URL || 'https://v14.nr-vault.ddev.site',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
  ],
  expect: {
    timeout: 10000,
  },
  timeout: 60000,
});
