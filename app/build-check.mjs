// Checks the build output after `npm run build` (postbuild).
//
// 1. Markers: the main bundle must contain a few names that only a complete build has.
// 2. Size budget: each bundle stays under a fixed size. One import from the @nextcloud/vue package
//    entry made the main bundle 1.3 MB larger, and nobody noticed for months (27.11.2026). A budget
//    turns such a jump into a failed build. Raise a limit on purpose, with the reason in the commit,
//    never to make a build pass.
import { readFileSync, existsSync, realpathSync } from 'fs'
import { gzipSync } from 'zlib'
import { join } from 'path'
import { fileURLToPath } from 'url'

export const MARKERS = { showVirtuProfDock: 1, 'app-shell': 1, CourseDetail: 1 }

// Bytes, about 8 % above the size of 27.11.2026 (Vite 8, Pinia 4, vue-router 5, after the import fix).
export const BUDGETS = {
	'js/learning.js': 3_750_000, // 3_489_131
	'js/learning-admin-settings.js': 500_000, // 461_812
	'js/learning-personal-settings.js': 485_000, // 448_126
	'js/learning-privacy-page.js': 170_000, // 155_932
	'js/learning-imprint-page.js': 105_000, // 97_238
	'css/learning.css': 635_000, // 585_860
	'css/learning-admin-settings.css': 75_000, // 69_554
	'css/learning-personal-settings.css': 80_000, // 74_403
	'css/learning-privacy-page.css': 9_200, // 8_465
	'css/learning-imprint-page.css': 2_200, // 1_952
}

export function checkBuild(root) {
	const errors = []
	const main = join(root, 'js/learning.js')
	if (!existsSync(main)) {
		return ['js/learning.js missing']
	}
	const js = readFileSync(main, 'utf8')
	for (const [key, min] of Object.entries(MARKERS)) {
		const count = (js.match(new RegExp(key, 'g')) || []).length
		if (count < min) {
			errors.push(`marker ${key}: expected >= ${min}, got ${count}`)
		}
	}
	const sizes = []
	for (const [file, limit] of Object.entries(BUDGETS)) {
		const path = join(root, file)
		if (!existsSync(path)) {
			errors.push(`${file} missing`)
			continue
		}
		const bytes = readFileSync(path)
		sizes.push(`${file} ${bytes.length} (gzip ${gzipSync(bytes).length}) of ${limit}`)
		if (bytes.length > limit) {
			errors.push(`${file} is ${bytes.length} bytes, budget ${limit} (+${bytes.length - limit})`)
		}
	}
	return errors.length ? errors : { sizes }
}

// realpath: Node resolves symlinks in import.meta.url but not in argv[1]; without it a call through a
// symlinked path would skip the check and exit 0.
if (process.argv[1] && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
	const result = checkBuild(process.cwd())
	if (Array.isArray(result)) {
		for (const e of result) console.error('BUILD CHECK FAILED: ' + e)
		process.exit(1)
	}
	for (const line of result.sizes) console.log('  ' + line)
	console.log('Build checks passed')
}
