// Playwright config for the persona sweep (run through run.sh, which sets PERSONA_STATE and
// NODE_PATH so that @playwright/test resolves from app/node_modules).
const { defineConfig } = require('@playwright/test')
const path = require('path')

module.exports = defineConfig({
	testDir: __dirname,
	testMatch: /persona\.sweep\.cjs$/,
	outputDir: path.join(__dirname, 'out', 'pw-results'),
	timeout: 30 * 60_000,
	retries: 0,
	workers: 1,
	fullyParallel: false,
	reporter: 'list',
	use: {
		headless: true,
		viewport: { width: 1400, height: 900 },
		locale: 'en-GB',
		acceptDownloads: true,
		actionTimeout: 10_000,
		navigationTimeout: 30_000,
		launchOptions: { executablePath: process.env.PERSONA_CHROMIUM || '/usr/bin/chromium' },
	},
})
