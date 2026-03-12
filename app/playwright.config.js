// Minimal E2E setup for Learning app exam flows.
const { defineConfig } = require('@playwright/test')
const path = require('path')

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  retries: 1,
  fullyParallel: false,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8080/apps/learning',
    headless: true,
    storageState: path.join(__dirname, 'tests/e2e/.auth/admin.json'),
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.js/,
    },
    {
      name: 'chromium',
      dependencies: ['setup'],
      testIgnore: /auth\.setup\.js/,
    },
  ],
})
