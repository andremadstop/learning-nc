// Playwright config for the screenshot run (through run.sh, which sets PERSONA_STATE and NODE_PATH
// so that @playwright/test resolves from app/node_modules). Window size and theme are set per
// browser context in shots.spec.cjs, from lib.mjs.
const { defineConfig } = require('@playwright/test')
const path = require('path')

module.exports = defineConfig({
	testDir: __dirname,
	testMatch: /shots\.spec\.cjs$/,
	outputDir: path.join(__dirname, 'out', 'pw-results'),
	timeout: 30 * 60_000,
	retries: 0,
	workers: 1,
	fullyParallel: false,
	reporter: 'list',
	use: {
		headless: true,
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
		// Native widgets (the dd/mm/yyyy of date inputs) follow the browser's UI language, not the page
		// locale; on Linux Chromium takes it from LANGUAGE/LANG, --lang alone left them German here.
		launchOptions: {
			executablePath: process.env.SHOTS_CHROMIUM || '/usr/bin/chromium',
			args: ['--lang=en-GB', '--font-render-hinting=none'],
			env: { ...process.env, LANGUAGE: 'en_GB:en', LANG: 'en_GB.UTF-8', LC_ALL: 'en_GB.UTF-8' },
		},
	},
})
