// persona-sweep/seed.mjs — seeds the throwaway instance through the app's own API.
//
// The instructor (not the admin) creates everything, as in the guide: two pools of eight
// questions (pools.json), the course "Annual safety training" with the group safety-staff
// enrolled, both pools added and required for certification at 75 % for 365 days, Leitner off.
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

const admin = await asAdmin('GET', '/settings/admin')
const wantGamification = state.GAMIFICATION === 'on' ? 'yes' : 'no'
if (admin.gamification_enabled !== wantGamification) throw new Error(`gamification_enabled is ${admin.gamification_enabled}, expected ${wantGamification}`)
if (admin.ai_provider !== 'disabled' && admin.ai_enabled !== false && admin.ai_enabled !== 'no') {
	throw new Error(`AI is not off on a fresh instance: provider=${admin.ai_provider} enabled=${admin.ai_enabled}`)
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
})
for (const [i, poolId] of poolIds.entries()) {
	await asInstructor('POST', `/courses/${course.id}/pools`, { poolId, sortOrder: i })
}
await asInstructor('PATCH', `/courses/${course.id}/cert-config`, {
	certEnabled: true, certPassPercent: 75, certRequiredPoolIds: poolIds, certValidityDays: 365,
})
// updateModeConfig turns every omitted key back on: send the full current map, Leitner off.
const detail = await asInstructor('GET', `/courses/${course.id}`)
const modeConfig = { ...(detail.mode_config || {}), leitner: false }
await asInstructor('PUT', `/courses/${course.id}/mode-config`, { modeConfig })

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
writeFileSync(join(HERE, 'out', 'seed.json'), JSON.stringify({ courseId: course.id, poolIds, modeConfig, answers }, null, 2))
console.log(`seed: course ${course.id}, pools ${poolIds.join(', ')}, mode_config ${JSON.stringify(modeConfig)}`)
