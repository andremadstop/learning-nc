// screenshots/seed.mjs — the demo data the screenshots need on top of the persona seed (GitHub #74).
//
// Usage (from run.sh): PERSONA_STATE=<out/instance.env> node seed.mjs <examples/app-tour> <bundle-out>
//
// Through the app's own API, as the instructor of the seeded course "Annual safety training":
//   - the certificate is valid for one year counted by the calendar (5.14: months instead of 365
//     days), and required documents are confirmed again at every renewal (#75);
//   - one question of the example course (lib.mjs: TOUR) becomes the pool "How to learn with this
//     app", optional in the course, so the Training picture shows the example's own hint, feedback
//     and media; its bundle (manifest with that question, plus its folder) goes to <bundle-out> for
//     `occ learning:import-question-media`.
// Writes shots-seed.json next to the state file and prints the new pool id (stdout, for run.sh).
import { cpSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { TOUR } from './lib.mjs'

const [tourDir, bundleOut] = process.argv.slice(2)
if (!tourDir || !bundleOut) throw new Error('usage: seed.mjs <examples/app-tour> <bundle-out>')
const stateFile = process.env.PERSONA_STATE
const state = {}
for (const line of readFileSync(stateFile, 'utf8').split('\n')) {
	const m = line.match(/^([A-Z_]+)='(.*)'$/)
	if (m) state[m[1]] = m[2]
}
const OUT = dirname(stateFile)
const personaSeed = JSON.parse(readFileSync(join(OUT, 'seed.json'), 'utf8'))
const api = `${state.URL}/index.php/apps/learning/api`

async function asInstructor(method, path, body) {
	const res = await fetch(api + path, {
		method,
		headers: {
			Authorization: 'Basic ' + Buffer.from(`instructor:${state.INSTRUCTOR_PASS}`).toString('base64'),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
		},
		body: body !== undefined ? JSON.stringify(body) : undefined,
	})
	const text = await res.text()
	if (!res.ok) throw new Error(`${method} ${path} → ${res.status}: ${text.slice(0, 300)}`)
	try { return JSON.parse(text) } catch { return null }
}

const courseId = personaSeed.courseId
const config = await asInstructor('PATCH', `/courses/${courseId}/cert-config`, { certValidityMonths: 12, certValidityUnit: 'months' })
const gate = await asInstructor('PUT', `/courses/${courseId}/video-gate`, { reconfirmPerCycle: true })
if (gate?.reconfirm_per_cycle !== true) throw new Error('reconfirm per cycle did not switch on: ' + JSON.stringify(gate))
if (config?.certValidityMonths !== 12) throw new Error('validity is not 12 months: ' + JSON.stringify(config).slice(0, 300))

const tourPool = JSON.parse(readFileSync(join(tourDir, 'pool.json'), 'utf8')).pools[TOUR.poolIndex]
const question = tourPool.questions.find((q) => q.exam_key === TOUR.examKey)
if (!question) throw new Error(`examples/app-tour has no ${TOUR.examKey}`)
const imported = await asInstructor('POST', '/pools/import', {
	jsonData: JSON.stringify({ pools: [{ name: tourPool.name, description: tourPool.description, questions: [question] }] }),
	dry_run: false,
	file_name: 'app-tour.json',
})
const poolId = imported?.pools?.[0]?.id
if (!poolId || imported.imported_questions !== 1) throw new Error('app-tour import incomplete: ' + JSON.stringify(imported))
await asInstructor('POST', `/courses/${courseId}/pools`, { poolId, sortOrder: 3 })

const manifest = JSON.parse(readFileSync(join(tourDir, 'bundle', TOUR.bundleLang, 'manifest.json'), 'utf8'))
manifest.questions = manifest.questions.filter((q) => q.exam_key === TOUR.examKey)
if (manifest.questions.length !== 1) throw new Error(`the app-tour bundle has no ${TOUR.examKey}`)
mkdirSync(bundleOut, { recursive: true })
writeFileSync(join(bundleOut, 'manifest.json'), JSON.stringify(manifest))
cpSync(join(tourDir, 'bundle', TOUR.bundleLang, TOUR.folder), join(bundleOut, TOUR.folder), { recursive: true })

writeFileSync(join(OUT, 'shots-seed.json'), JSON.stringify({
	tour: { poolId, poolName: tourPool.name, question: question.text, wrongAnswer: question.answers.find((a) => !a.is_correct).text },
}, null, 2))
process.stderr.write(`screenshots seed: validity 12 months, reconfirm per cycle on, app-tour pool ${poolId} in course ${courseId}\n`)
process.stdout.write(String(poolId))
