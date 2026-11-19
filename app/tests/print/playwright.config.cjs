// GitHub #80: the certificate printed to PDF, checked for paper size, page count and how much of
// the page the certificate fills. Runs without Nextcloud: `npm run test:print`.
// GitHub #94: the PDF download (certificate-pdf.spec.cjs) runs on the desktop and on a phone in
// Chromium and WebKit (Safari's engine): `npm run test:pdf` (scripts/pdf-gate.sh).
const { defineConfig, devices } = require('@playwright/test')

const PORT = Number(process.env.PRINT_HARNESS_PORT || 5199)

// the system Chromium, like the persona sweep; PRINT_CHROMIUM='' uses Playwright's own download
const chromiumLaunch = process.env.PRINT_CHROMIUM === ''
	? {}
	: { executablePath: process.env.PRINT_CHROMIUM || '/usr/bin/chromium' }

const PHONE = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true }

module.exports = defineConfig({
	testDir: __dirname,
	testMatch: /\.spec\.cjs$/,
	timeout: 60_000,
	retries: 0,
	reporter: 'list',
	use: {
		baseURL: `http://127.0.0.1:${PORT}`,
		headless: true,
	},
	projects: [
		{
			// the print gate (npm run test:print)
			name: 'chromium',
			testIgnore: /certificate-pdf\.spec\.cjs$/,
			use: { browserName: 'chromium', launchOptions: chromiumLaunch },
		},
		{
			name: 'pdf-desktop',
			testMatch: /certificate-pdf\.spec\.cjs$/,
			use: { browserName: 'chromium', launchOptions: chromiumLaunch },
		},
		{
			name: 'chromium-phone',
			testMatch: /certificate-pdf\.spec\.cjs$/,
			use: { browserName: 'chromium', launchOptions: chromiumLaunch, ...PHONE },
		},
		{
			// Playwright's own WebKit build. Its system libraries are Ubuntu's, so on other hosts it
			// runs in Playwright's container (scripts/pdf-gate.sh sets PDF_WEBKIT_WS).
			name: 'webkit-phone',
			testMatch: /certificate-pdf\.spec\.cjs$/,
			use: {
				...devices['iPhone 13'],
				browserName: 'webkit',
				launchOptions: {},
				...PHONE,
				...(process.env.PDF_WEBKIT_WS ? { connectOptions: { wsEndpoint: process.env.PDF_WEBKIT_WS } } : {}),
			},
		},
	],
	webServer: {
		cwd: require('path').resolve(__dirname, '../..'),
		command: `npx vite --config tests/print/vite.config.mjs --host 127.0.0.1 --port ${PORT}`,
		url: `http://127.0.0.1:${PORT}/index.html`,
		reuseExistingServer: false,
		timeout: 60_000,
	},
})
