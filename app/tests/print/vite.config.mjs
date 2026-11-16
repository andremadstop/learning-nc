// GitHub #80: serves the print harness (index.html) with the app's real Certificate component.
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const here = dirname(fileURLToPath(import.meta.url))

export default defineConfig({
	root: here,
	define: {
		__VUE_OPTIONS_API__: true,
		__VUE_PROD_DEVTOOLS__: false,
		appName: JSON.stringify('learning'),
		appVersion: JSON.stringify('0.0.0'),
	},
	plugins: [vue()],
	resolve: {
		alias: { '@': resolve(here, '../../src') },
	},
	server: { strictPort: true },
	logLevel: 'warn',
})
