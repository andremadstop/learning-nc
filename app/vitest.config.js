import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// A fixed time zone for every test, like the fixed locale in tests/unit/setup-locale.js: dates
// shown in local time must not depend on the machine (UTC+14 moved a 12:00 UTC date to the next
// day, 27.11.2026). Set here, before the workers start, so they inherit it.
process.env.TZ = 'Europe/Berlin'

export default defineConfig({
  plugins: [vue()],
  test: {
    include: ['tests/unit/**/*.test.js'],
    exclude: ['tests/e2e/**'],
    css: false,
    environment: 'happy-dom',
    // A fixed Nextcloud locale, so date and time formatting does not depend on the machine.
    setupFiles: ['tests/unit/setup-locale.js'],
    // The first test of a file pays for importing its components. Under load (three suites at
    // once, 27.11.2026) such tests reached 4.0–4.9 s of the 5 s default, and a CI run took one
    // past it. 20 s still fails a real hang; tests that compile every component set 60 s.
    testTimeout: 20000,
    hookTimeout: 30000,
    clearMocks: true,
    // `npm run test:coverage` (GitHub #65, CI on every push). The thresholds are a floor: the
    // coverage of 27.11.2026, rounded down (lines 64.81, statements 63.82, functions 55.00,
    // branches 54.17 %). Old code owes nothing; new code without tests shows up as a drop. Raise
    // the floor when coverage has grown, lower it only with the reason in the commit. PHP:
    // scripts/check-php-coverage.py.
    coverage: {
      provider: 'v8',
      thresholds: { lines: 64, statements: 63, functions: 54, branches: 53 },
      include: ['src/**/*.{js,ts,vue}'],
      exclude: ['src/l10n/**'],
      reporter: ['text-summary', 'html', 'lcov', 'json-summary'],
      reportsDirectory: 'coverage/js',
    },
    server: {
      deps: {
        inline: [/@nextcloud\/vue/],
      },
    },
  },
})
