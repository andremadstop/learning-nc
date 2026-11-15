// persona-sweep/report.mjs — out/findings.json → out/report.md
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const OUT = join(dirname(fileURLToPath(import.meta.url)), 'out')
const state = {}
for (const line of readFileSync(process.env.PERSONA_STATE, 'utf8').split('\n')) {
	const m = line.match(/^([A-Z_]+)='(.*)'$/)
	if (m && !/PASS/.test(m[1])) state[m[1]] = m[2] // passwords never reach the report
}
const stations = existsSync(join(OUT, 'findings.json')) ? JSON.parse(readFileSync(join(OUT, 'findings.json'), 'utf8')) : []

const KINDS = {
	http: 'App request ≥ 400',
	console: 'Console error',
	pageerror: 'Uncaught exception',
	empty: 'Empty main area',
	german: 'German text in the English UI',
	'toast-without-write': 'Success toast without a successful write request',
	'toast-despite-error': 'Success toast although a request failed',
	'error-toast': 'Error toast',
	'station-error': 'Station could not be completed',
	'deep-link': 'Deep link',
	'course-missing': 'Course not in the course list', 'pool-missing': 'Pool not in the pool list',
	exam: 'Exam', certificate: 'Certificate', print: 'Print', download: 'Download', compliance: 'Compliance report',
}
const esc = (s) => String(s).replace(/\|/g, '\\|').replace(/\n/g, ' ')

const counts = {}
for (const s of stations) for (const f of s.findings) counts[f.kind] = (counts[f.kind] || 0) + 1
const withFindings = stations.filter((s) => s.findings.length)
const personas = [...new Set(stations.map((s) => s.persona))]

let md = `# Persona sweep — ${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC\n\n`
md += `- App snapshot: \`${state.COMMIT || '?'}\` (${state.REF || 'HEAD'}), Nextcloud 33 + PostgreSQL 16, English UI\n`
md += `- Gamification: **${state.GAMIFICATION || 'off'}**, AI: off, Leitner: off in the course, certification 75 % with two required pools\n`
md += `- Personas: ${personas.join(', ')}\n`
const toasts = stations.flatMap((s) => (s.toasts || []).map((t) => `${s.persona}: ${s.station} — ${t}`))
md += `- Stations: ${stations.length}, with findings: ${withFindings.length}, buttons clicked: ${stations.reduce((n, s) => n + (s.clicked || 0), 0)}\n`
md += `- Toasts seen (all kinds): ${toasts.length}${toasts.length ? '' : ' — **the toast check saw none, so "success toast without a write request" is unproven for this run**'}\n`
md += '- Contrast (axe-core): **not checked** — axe-core is not in app/node_modules and the sweep installs nothing.\n'
md += '- The seed enrols the administrator as co-instructor of the course (a Nextcloud admin who is not a member does not see it).\n'
md += '- Destructive buttons (delete, remove, revoke, unenrol, archive, reset, generate/rotate key) and exam starts are never clicked by the generic sweep.\n\n'

md += '## Summary\n\n| Kind | Findings |\n|---|---|\n'
for (const [k, n] of Object.entries(counts).sort((a, b) => b[1] - a[1])) md += `| ${KINDS[k] || k} | ${n} |\n`
if (!Object.keys(counts).length) md += '| — | 0 |\n'

// The same finding at many stations is one problem: list each once with where it showed up.
const byDetail = new Map()
for (const s of stations) {
	for (const f of s.findings) {
		const key = `${f.kind}\u0000${f.detail}`
		if (!byDetail.has(key)) byDetail.set(key, { ...f, where: [] })
		byDetail.get(key).where.push(`${s.persona}: ${s.station}`)
	}
}
md += '\n## Findings (deduplicated)\n\n'
for (const kind of Object.keys(KINDS).concat(Object.keys(counts).filter((k) => !KINDS[k]))) {
	const items = [...byDetail.values()].filter((f) => f.kind === kind)
	if (!items.length) continue
	md += `### ${KINDS[kind] || kind} (${items.length})\n\n`
	for (const f of items) {
		const where = f.where.length > 4 ? `${f.where.slice(0, 4).join('; ')}; … (${f.where.length} stations)` : f.where.join('; ')
		md += `- ${esc(f.detail)}  \n  _at_ ${esc(where)}\n`
	}
	md += '\n'
}

if (toasts.length) md += `## Toasts seen\n\n${toasts.map((t) => `- ${esc(t)}`).join('\n')}\n\n`
md += '## Stations\n\n| Persona | Station | URL | Clicked | Findings | Screenshot |\n|---|---|---|---|---|---|\n'
for (const s of stations) {
	md += `| ${esc(s.persona)} | ${esc(s.station)} | \`${esc(s.url)}\` | ${s.clicked || 0} | ${s.findings.length ? s.findings.map((f) => f.kind).join(', ') : '—'} | ${s.screenshot ? `shots/${s.screenshot}` : ''} |\n`
}
writeFileSync(join(OUT, 'report.md'), md)
console.log(`report: ${stations.length} stations, ${withFindings.length} with findings`)
