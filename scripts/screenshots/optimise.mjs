// screenshots/optimise.mjs — raw pictures → optimised PNGs in the repository, then the budget check.
//
// Usage: node optimise.mjs <out/raw> <repo-root>
// ImageMagick: metadata stripped (no time stamps, so an unchanged screen gives the same file),
// 256 colours without dithering (flat interface colours stay flat), maximum zlib compression.
// Exit 1 when a picture is missing, not a PNG, the wrong size or over the budget (lib.mjs).
import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { SHOTS, budgetFindings } from './lib.mjs'

const [rawDir, repoRoot] = process.argv.slice(2)
if (!rawDir || !repoRoot) throw new Error('usage: optimise.mjs <out/raw> <repo-root>')

const files = {}
for (const shot of SHOTS) {
	const raw = join(rawDir, `${shot.id}.png`)
	if (!existsSync(raw)) continue
	const dest = join(repoRoot, shot.dest)
	mkdirSync(dirname(dest), { recursive: true })
	execFileSync('magick', [raw, '-strip', '+dither', '-colors', '256',
		'-define', 'png:compression-level=9', '-define', 'png:compression-filter=5',
		'-define', 'png:exclude-chunks=date,time', dest])
	files[shot.id] = { bytes: statSync(dest).size, buf: readFileSync(dest) }
	console.log(`${shot.dest}  ${Math.round(files[shot.id].bytes / 1024)} KB`)
}
const findings = budgetFindings(files)
for (const f of findings) console.error(`screenshots: ${f}`)
process.exit(findings.length ? 1 : 0)
