// The Vitest config for mutation testing: the normal one, limited to the test files that
// stryker.config.mjs found with `vitest related` (passed in STRYKER_TEST_FILES).
import base from './vitest.config.js'

const files = JSON.parse(process.env.STRYKER_TEST_FILES ?? '[]')
if (!files.length) throw new Error('stryker.vitest.config.mjs: STRYKER_TEST_FILES is empty; run through `npx stryker run`')

// Spread, not mergeConfig: mergeConfig appends arrays and would keep tests/unit/**.
export default { ...base, test: { ...base.test, include: files } }
