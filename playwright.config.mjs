import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests',
  testMatch: ['browser-test.mjs', 'campaign-skillcheck.mjs'],
  timeout: 90000,
  globalSetup: './tests/global-setup.mjs',
  use: {
    baseURL: 'https://devcloud.andrestiebitz.de',
    storageState: 'tests/auth-state.json',
    screenshot: 'only-on-failure',
    video: 'off',
    ignoreHTTPSErrors: true,
  },
  workers: 1,  // Sequential — shared session
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
  ],
})
