import { defineConfig } from 'vitest/config'
import vue2 from '@vitejs/plugin-vue2'

export default defineConfig({
  plugins: [vue2()],
  test: {
    include: ['tests/unit/**/*.test.js'],
    exclude: ['tests/e2e/**'],
    css: false,
    environment: 'happy-dom',
    server: {
      deps: {
        inline: [/@nextcloud\/vue/],
      },
    },
  },
})
