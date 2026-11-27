import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  test: {
    include: ['tests/unit/**/*.test.js'],
    exclude: ['tests/e2e/**'],
    css: false,
    environment: 'happy-dom',
    // A fixed Nextcloud locale, so date and time formatting does not depend on the machine.
    setupFiles: ['tests/unit/setup-locale.js'],
    // `npm run test:coverage` (GitHub #65): a report only, no threshold.
    coverage: {
      provider: 'v8',
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
