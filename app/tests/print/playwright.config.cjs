// GitHub #80: the certificate printed to PDF, checked for paper size, page count and how much of
// the page the certificate fills. Runs without Nextcloud: `npm run test:print`.
const { defineConfig } = require('@playwright/test')

const PORT = Number(process.env.PRINT_HARNESS_PORT || 5199)

module.exports = defineConfig({
	testDir: __dirname,
	testMatch: /\.spec\.cjs$/,
	timeout: 60_000,
	retries: 0,
	reporter: 'list',
	use: {
		baseURL: `http://127.0.0.1:${PORT}`,
		headless: true,
		// the system Chromium, like the persona sweep; PRINT_CHROMIUM='' uses Playwright's own download
		launchOptions: process.env.PRINT_CHROMIUM === ''
			? {}
			: { executablePath: process.env.PRINT_CHROMIUM || '/usr/bin/chromium' },
	},
	webServer: {
		cwd: require('path').resolve(__dirname, '../..'),
		command: `npx vite --config tests/print/vite.config.mjs --host 127.0.0.1 --port ${PORT}`,
		url: `http://127.0.0.1:${PORT}/index.html`,
		reuseExistingServer: false,
		timeout: 60_000,
	},
})
