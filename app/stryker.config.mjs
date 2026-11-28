// Mutation testing (nightly, report only): does a test fail when the code is wrong? Coverage only
// says the code ran; a test that accepts 200 or 500 covers everything and catches nothing (memory:
// feedback_string_derived_symbols). Stryker changes the code in small ways and reports each change
// no test noticed.
//
// Scope: modules where a silent error costs most (certificate validity and cycle, AI consent,
// exam wait). 4 modules took 4 minutes locally (28.11.2026, score 82.7 %, the same under Vitest 4 and 5).
// Stryker is installed by the nightly job only (pinned, --no-save), so it is not in the lockfile.
// Local run: npm install --no-save @stryker-mutator/core@10.1.1 @stryker-mutator/vitest-runner@10.1.1
//            npx stryker run    (report: reports/mutation/index.html)
import { execFileSync } from 'child_process'
import { readFileSync, mkdirSync } from 'fs'
import { relative } from 'path'

const MUTATE = [
	'src/utils/certValidity.js',
	'src/utils/certificationCycle.js',
	'src/utils/aiConsent.js',
	'src/utils/examWait.js',
]

// Only the tests that import a mutated module, directly or not (`vitest related`). The runner's own
// `vitest.related` does nothing under Vitest 5 (vitest-runner 10.1.1 sets ctx.config.related, which
// Vitest 5 ignores), so every test file ran in the sandbox: tests that import scripts outside app/
// and tests that read src/ as text (apiContract) failed on the instrumented code (28.11.2026).
// Stryker loads this file once; the list reaches the test workers through the environment.
if (!process.env.STRYKER_TEST_FILES) {
	mkdirSync('reports/mutation', { recursive: true })
	const out = 'reports/mutation/related.json'
	execFileSync('npx', ['vitest', 'related', ...MUTATE, '--run', '--reporter=json', `--outputFile=${out}`], { stdio: 'ignore' })
	const files = JSON.parse(readFileSync(out, 'utf8')).testResults.map((r) => relative(process.cwd(), r.name))
	if (!files.length) throw new Error('stryker.config.mjs: vitest related found no tests for ' + MUTATE.join(', '))
	process.env.STRYKER_TEST_FILES = JSON.stringify(files)
}

export default {
	testRunner: 'vitest',
	vitest: { configFile: 'stryker.vitest.config.mjs', related: false },
	mutate: MUTATE,
	coverageAnalysis: 'perTest',
	reporters: ['clear-text', 'html', 'json'],
	htmlReporter: { fileName: 'reports/mutation/index.html' },
	jsonReporter: { fileName: 'reports/mutation/mutation.json' },
	// a report, not a gate: break stays off
	thresholds: { high: 90, low: 80, break: null },
}
