// Minimal E2E setup for Learning app exam flows.
const { defineConfig } = require('@playwright/test')
const path = require('path')

const defaultBaseURL = process.env.E2E_BASE_URL || 'http://192.168.178.65:8080/apps/learning'
process.env.E2E_BASE_URL = defaultBaseURL

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  retries: 1,
  fullyParallel: false,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: defaultBaseURL,
    headless: true,
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
      use: { storageState: path.join(__dirname, 'tests/e2e/.auth/admin.json') },
    },
  ],
})
