/**
 * visual-diff.mjs — compare the screenshots of two Persona-Gate runs (GitHub #65).
 *
 *   node scripts/persona-sweep/visual-diff.mjs <baseline> <current> [--out <dir>] [--threshold 0.1]
 *
 * <baseline> and <current> are run directories (scripts/persona-sweep/out/gamification-off, or a
 * downloaded nightly artifact) or their shots/ folders. Screenshots are matched by persona and
 * station, not by file name: the running number in "admin--018-top-courses.png" shifts whenever a
 * station is added. Each pair is compared with pixelmatch; the report lists every station as
 * unchanged, changed (with the share of changed pixels and a diff image), resized, new or gone.
 *
 * This is a basis, not a gate: there are no approved reference images in the repository, and the
 * script always exits 0 after writing the report (2 on a usage error). Output, by default in
 * scripts/persona-sweep/out/visual-diff/ (gitignored): report.md, report.json, diff/<station>.png.
 *
 * pixelmatch and pngjs are pinned devDependencies in app/node_modules (no second install).
 */
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { basename, dirname, join, resolve } from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath, pathToFileURL } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const APP = resolve(HERE, '../../app')
const requireApp = createRequire(join(APP, 'package.json'))
const { PNG } = requireApp('pngjs')
const pixelmatch = (await import(pathToFileURL(join(APP, 'node_modules/pixelmatch/index.js')).href)).default

/** "admin--018-top-courses.png" -> "admin--top-courses" (persona and station, no running number). */
export function shotKey(fileName) {
	const name = basename(fileName).replace(/\.png$/i, '')
	const m = /^(.*?)--\d+-(.*)$/.exec(name)
	return m ? `${m[1]}--${m[2]}` : name
}

/** Map station key -> file path for one run; a repeated key gets "#2", "#3" in file order. */
export function indexShots(dir) {
	const shots = existsSync(join(dir, 'shots')) && statSync(join(dir, 'shots')).isDirectory() ? join(dir, 'shots') : dir
	const map = new Map()
	for (const f of readdirSync(shots).filter((n) => /\.png$/i.test(n)).sort()) {
		let key = shotKey(f)
		for (let n = 2; map.has(key); n++) {
			key = `${shotKey(f)}#${n}`
		}
		map.set(key, join(shots, f))
	}
	return map
}

/**
 * Compare two PNG buffers.
 * @return {{status: 'unchanged'|'changed'|'resized', diffPixels: number, ratio: number, size: string, diff: Buffer|null}}
 */
export function comparePng(bufA, bufB, { threshold = 0.1 } = {}) {
	const a = PNG.sync.read(bufA)
	const b = PNG.sync.read(bufB)
	if (a.width !== b.width || a.height !== b.height) {
		return { status: 'resized', diffPixels: 0, ratio: 1, size: `${a.width}x${a.height} -> ${b.width}x${b.height}`, diff: null }
	}
	const out = new PNG({ width: a.width, height: a.height })
	const diffPixels = pixelmatch(a.data, b.data, out.data, a.width, a.height, { threshold })
	const ratio = diffPixels / (a.width * a.height)
	return {
		status: diffPixels === 0 ? 'unchanged' : 'changed',
		diffPixels,
		ratio,
		size: `${a.width}x${a.height}`,
		diff: diffPixels === 0 ? null : PNG.sync.write(out),
	}
}

/** Compare two runs; writes diff images to diffDir when given. */
export function compareRuns(baselineDir, currentDir, { threshold = 0.1, diffDir = null } = {}) {
	const base = indexShots(baselineDir)
	const cur = indexShots(currentDir)
	const entries = []
	for (const key of [...new Set([...base.keys(), ...cur.keys()])].sort()) {
		if (!cur.has(key)) {
			entries.push({ key, status: 'gone', baseline: base.get(key) })
			continue
		}
		if (!base.has(key)) {
			entries.push({ key, status: 'new', current: cur.get(key) })
			continue
		}
		let r
		try {
			r = comparePng(readFileSync(base.get(key)), readFileSync(cur.get(key)), { threshold })
		} catch (e) {
			entries.push({ key, status: 'unreadable', error: String(e && e.message ? e.message : e) })
			continue
		}
		const entry = { key, status: r.status, diffPixels: r.diffPixels, ratio: r.ratio, size: r.size, baseline: base.get(key), current: cur.get(key) }
		if (r.diff && diffDir) {
			mkdirSync(diffDir, { recursive: true })
			entry.diffImage = join(diffDir, key.replace(/[^\w.#-]+/g, '_') + '.png')
			writeFileSync(entry.diffImage, r.diff)
		}
		entries.push(entry)
	}
	return entries
}

const ORDER = ['changed', 'resized', 'new', 'gone', 'unreadable', 'unchanged']

/** Markdown report: counts, then every station that is not unchanged, most changed first. */
export function renderReport(entries, { baseline, current, threshold }) {
	const count = (s) => entries.filter((e) => e.status === s).length
	const lines = [
		'# Persona-Gate: visual comparison',
		'',
		`- Baseline: \`${baseline}\``,
		`- Current: \`${current}\``,
		`- pixelmatch threshold: ${threshold}`,
		'',
		'| Status | Stations |',
		'|---|---:|',
		...ORDER.map((s) => `| ${s} | ${count(s)} |`),
		'',
	]
	const shown = entries.filter((e) => e.status !== 'unchanged')
		.sort((x, y) => ORDER.indexOf(x.status) - ORDER.indexOf(y.status) || (y.ratio ?? 0) - (x.ratio ?? 0) || x.key.localeCompare(y.key))
	if (shown.length === 0) {
		lines.push('No visual changes.')
	} else {
		lines.push('| Station | Status | Changed pixels | Size | Diff |', '|---|---|---:|---|---|')
		for (const e of shown) {
			const px = e.status === 'changed' ? `${e.diffPixels} (${(e.ratio * 100).toFixed(2)} %)` : ''
			const diff = e.diffImage ? `\`diff/${basename(e.diffImage)}\`` : (e.error ?? '')
			lines.push(`| ${e.key} | ${e.status} | ${px} | ${e.size ?? ''} | ${diff} |`)
		}
	}
	return lines.join('\n') + '\n'
}

function main(argv) {
	const args = []
	let out = join(HERE, 'out', 'visual-diff')
	let threshold = 0.1
	for (let i = 0; i < argv.length; i++) {
		if (argv[i] === '--out') {
			out = resolve(argv[++i] ?? '')
		} else if (argv[i] === '--threshold') {
			threshold = Number(argv[++i])
		} else {
			args.push(argv[i])
		}
	}
	if (args.length !== 2 || !Number.isFinite(threshold) || threshold < 0 || threshold > 1 || !args.every((d) => existsSync(d) && statSync(d).isDirectory())) {
		process.stderr.write('usage: visual-diff.mjs <baseline-run-dir> <current-run-dir> [--out <dir>] [--threshold 0..1]\n')
		return 2
	}
	const [baseline, current] = args.map((d) => resolve(d))
	const entries = compareRuns(baseline, current, { threshold, diffDir: join(out, 'diff') })
	mkdirSync(out, { recursive: true })
	writeFileSync(join(out, 'report.json'), JSON.stringify({ baseline, current, threshold, entries }, null, 2) + '\n')
	const md = renderReport(entries, { baseline, current, threshold })
	writeFileSync(join(out, 'report.md'), md)
	process.stdout.write(md.split('\n').slice(0, 16).join('\n') + `\nReport: ${join(out, 'report.md')}\n`)
	return 0
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	process.exitCode = main(process.argv.slice(2))
}
