// Minimal E2E setup for Learning app exam flows.
const { defineConfig } = require('@playwright/test')

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  retries: 1,
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8080/apps/learning',
    headless: true,
  },
})
