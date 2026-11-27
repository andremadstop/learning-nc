// Mutation testing (nightly, report only): does a test fail when the code is wrong? Coverage only
// says the code ran; a test that accepts 200 or 500 covers everything and catches nothing (memory:
// feedback_string_derived_symbols). Stryker changes the code in small ways and reports each change
// no test noticed.
//
// Scope: modules where a silent error costs most (certificate validity and cycle, AI consent,
// exam wait). 4 modules took 8 minutes on 4 cores (27.11.2026, score 82.7 %).
// Stryker is installed by the nightly job only (pinned, --no-save), so it is not in the lockfile.
// Local run: npm install --no-save @stryker-mutator/core@10.1.1 @stryker-mutator/vitest-runner@10.1.1
//            npx stryker run    (report: reports/mutation/index.html)
export default {
	testRunner: 'vitest',
	vitest: { configFile: 'vitest.config.js', related: true },
	mutate: [
		'src/utils/certValidity.js',
		'src/utils/certificationCycle.js',
		'src/utils/aiConsent.js',
		'src/utils/examWait.js',
	],
	coverageAnalysis: 'perTest',
	reporters: ['clear-text', 'html', 'json'],
	htmlReporter: { fileName: 'reports/mutation/index.html' },
	jsonReporter: { fileName: 'reports/mutation/mutation.json' },
	// a report, not a gate: break stays off
	thresholds: { high: 90, low: 80, break: null },
}
