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
		// --lang: native widgets (date inputs) follow the browser UI language, not the page locale.
		launchOptions: { executablePath: process.env.PERSONA_CHROMIUM || '/usr/bin/chromium', args: ['--lang=en-GB'] },
	},
})
