// persona-sweep/seed.mjs — seeds the throwaway instance through the app's own API.
//
// The instructor (not the admin) creates everything, as in the guide: two pools of eight
// questions (pools.json), the course "Annual safety training" with the group safety-staff
// enrolled, created with the course profile "mandatory" (Leitner, tools, social and game modes
// off), both pools added and required for certification at 75 % for 365 days.
// GAMIFICATION=on (an IT course as before #36): gamification on, the course keeps every mode
// (Leitner included), and AI is switched on with an Ollama connection that nothing answers —
// the AI parts of the interface show, every AI call fails (no external provider, no key).
// Basic auth plus `OCS-APIRequest: true` passes Nextcloud's CSRF check for app routes.
// Writes out/seed.json (course and pool ids) for the sweep.
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
export function readState(file = process.env.PERSONA_STATE) {
	const state = {}
	for (const line of readFileSync(file, 'utf8').split('\n')) {
		const m = line.match(/^([A-Z_]+)='(.*)'$/)
		if (m) state[m[1]] = m[2]
	}
	return state
}

const state = readState()
const api = `${state.URL}/index.php/apps/learning/api`

async function call(user, pass, method, path, body) {
	const res = await fetch(api + path, {
		method,
		headers: {
			Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
		},
		body: body !== undefined ? JSON.stringify(body) : undefined,
	})
	const text = await res.text()
	let json = null
	try { json = JSON.parse(text) } catch { /* not JSON */ }
	if (!res.ok) throw new Error(`${method} ${path} → ${res.status}: ${text.slice(0, 300)}`)
	return json
}
const asInstructor = (m, p, b) => call('instructor', state.INSTRUCTOR_PASS, m, p, b)
const asAdmin = (m, p, b) => call('admin', state.ADMIN_PASS, m, p, b)

const itCourse = state.GAMIFICATION === 'on'
let admin = await asAdmin('GET', '/settings/admin')
const wantGamification = itCourse ? 'yes' : 'no'
if (admin.gamification_enabled !== wantGamification) throw new Error(`gamification_enabled is ${admin.gamification_enabled}, expected ${wantGamification}`)
if (admin.ai_provider !== 'disabled' && admin.ai_enabled !== false && admin.ai_enabled !== 'no') {
	throw new Error(`AI is not off on a fresh instance: provider=${admin.ai_provider} enabled=${admin.ai_enabled}`)
}
if (itCourse) {
	// Through the admin form's own endpoint, not occ: saveAdmin also moves the change into the
	// connection snapshot that consent and transport read. Port 9 (discard) on loopback: no answer.
	const form = {
		daily_challenge_enabled: admin.daily_challenge_enabled ?? 'no',
		default_language: admin.default_language ?? 'en',
		max_import_size_mb: Number(admin.max_import_size_mb ?? 5),
		gamification_enabled: 'yes',
		allow_course_instructor_fallback: admin.allow_course_instructor_fallback ?? 'no',
		exam_attempt_limit_per_day: Number(admin.exam_attempt_limit_per_day ?? 5),
		exam_attempt_cooldown_minutes: Number(admin.exam_attempt_cooldown_minutes ?? 10),
		ai_provider: 'ollama',
		ai_ollama_url: 'http://127.0.0.1:9',
		ai_ollama_model: 'llama3',
		ai_enabled: 'yes',
	}
	await asAdmin('PUT', '/settings/admin', form)
	admin = await asAdmin('GET', '/settings/admin')
	if (admin.ai_enabled !== 'yes' || admin.ai_provider !== 'ollama') throw new Error(`AI did not switch on: provider=${admin.ai_provider} enabled=${admin.ai_enabled}`)
}

const poolsDoc = readFileSync(join(HERE, 'pools.json'), 'utf8')
const imported = await asInstructor('POST', '/pools/import', { jsonData: poolsDoc, dry_run: false, file_name: 'pools.json' })
const poolIds = imported.pools.map((p) => p.id)
if (poolIds.length !== 2 || poolIds.some((id) => !id) || imported.imported_questions !== 16) {
	throw new Error('pool import incomplete: ' + JSON.stringify(imported))
}

const course = await asInstructor('POST', '/courses', {
	title: 'Annual safety training',
	description: 'Fire safety and data protection, once a year.',
	ncGroupId: 'safety-staff',
	// GitHub #67: the mandatory-training scenario starts from the course profile, as the guide says;
	// the IT course takes the default profile (every mode on).
	profile: itCourse ? 'it' : 'mandatory',
})
for (const [i, poolId] of poolIds.entries()) {
	await asInstructor('POST', `/courses/${course.id}/pools`, { poolId, sortOrder: i })
}
await asInstructor('PATCH', `/courses/${course.id}/cert-config`, {
	certEnabled: true, certPassPercent: 75, certRequiredPoolIds: poolIds, certValidityDays: 365,
})
// The profile alone must have switched the course over: nothing is patched afterwards, so a
// profile that stops working fails the seed instead of being covered up.
const detail = await asInstructor('GET', `/courses/${course.id}`)
const modeConfig = detail.mode_config || {}
if (!itCourse) {
	const mustBeOff = ['leitner', 'tools', 'classbook', 'buddies', 'duel', 'gameshow', 'league', 'abenteuer', 'linkedin']
	const stillOn = mustBeOff.filter((k) => modeConfig[k] !== false)
	if (stillOn.length) throw new Error(`profile 'mandatory' left on: ${stillOn.join(', ')} (${JSON.stringify(modeConfig)})`)
} else if (Object.values(modeConfig).some((v) => v === false)) {
	throw new Error(`IT course has modes off: ${JSON.stringify(modeConfig)}`)
}

// The administrator runs the course with the instructor (co-instructor). A Nextcloud admin who
// is not a member does not see the course in the list, and the student view is only meaningful
// for someone who leads the course.
await asInstructor('POST', `/courses/${course.id}/members`, { userId: 'admin', role: 'instructor' })

const members = await asInstructor('GET', `/courses/${course.id}/members`)
const memberIds = (Array.isArray(members) ? members : members.members || []).map((m) => m.user_id)
for (const uid of ['learner1', 'learner2', 'learner3']) {
	if (!memberIds.includes(uid)) throw new Error(`${uid} was not enrolled through the group: ${JSON.stringify(members).slice(0, 300)}`)
}

const answers = {}
for (const pool of JSON.parse(poolsDoc).pools) {
	for (const q of pool.questions) answers[q.text] = q.answers.find((a) => a.is_correct).text
}
// poolOwner: the only persona whose pool list must show the pools (nothing is shared).
writeFileSync(join(HERE, 'out', 'seed.json'), JSON.stringify({
	courseId: course.id, poolIds, poolOwner: 'instructor', modeConfig,
	gamification: itCourse, ai: admin.ai_enabled === 'yes' ? `${admin.ai_provider} (no backend answers)` : 'off',
	answers,
}, null, 2))
console.log(`seed: course ${course.id}, pools ${poolIds.join(', ')}, AI ${admin.ai_enabled}, mode_config ${JSON.stringify(modeConfig)}`)
