// persona-sweep/seed.mjs — seeds the throwaway instance through the app's own API.
//
// The instructor (not the admin) creates everything, as in the guide: two pools of eight
// questions (pools.json), the course "Annual safety training" with the group safety-staff
// enrolled, created with the course profile "mandatory" (Leitner, tools, social and game modes
// off), both pools added and required for certification at 75 % for 365 days.
// GAMIFICATION=on (an IT course as before #36): gamification on, the course keeps every mode
// (Leitner included), and AI is switched on with an Ollama connection that nothing answers —
// the AI parts of the interface show, every AI call fails (no external provider, no key).
// GitHub #66: a third pool, "Question media demo", not required for the certificate, holds one
// question from the generic fixture (app/tests/fixtures/question-media, exam_key demo-q1); run.sh
// then imports its hint, feedback per option, background, key takeaway, intro and dialog with
// `occ learning:import-question-media`, so the sweep can answer it in Training.
// C5: three Markdown files as learning materials, two of them required (#75; the exam waits for
// them in the mandatory-training run), one logo in the administrator's certificate logo library
// chosen for the course (#78), and the organisation name on the course's certificates (#77).
// C9b: one Fire safety question is long, with a 120-character URL in it (#96, weak questions at
// phone width), and the administrator shares a pool "View only" with the instructor (#97, #98).
// Basic auth plus `OCS-APIRequest: true` passes Nextcloud's CSRF check for app routes.
// Writes seed.json (course and pool ids) for the sweep next to the state file (out/ by default;
// scripts/screenshots runs its own instance in its own folder, PERSONA_OUT).
import { readFileSync, writeFileSync } from 'node:fs'
import { deflateSync } from 'node:zlib'
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

// GitHub #66: the media question, taken from the fixture as it is (not copied into pools.json).
const MEDIA_KEY = 'demo-q1'
const fixturePool = JSON.parse(readFileSync(join(HERE, '..', '..', 'app', 'tests', 'fixtures', 'question-media', 'pool.json'), 'utf8'))
const mediaQuestion = fixturePool.questions.find((q) => q.exam_key === MEDIA_KEY)
if (!mediaQuestion) throw new Error(`the question-media fixture has no ${MEDIA_KEY}`)
const mediaImport = await asInstructor('POST', '/pools/import', {
	jsonData: JSON.stringify({ pools: [{ name: fixturePool.name, description: fixturePool.description, questions: [mediaQuestion] }] }),
	dry_run: false,
	file_name: 'question-media-demo.json',
})
const mediaPoolId = mediaImport.pools?.[0]?.id
if (!mediaPoolId || mediaImport.imported_questions !== 1) throw new Error('media pool import incomplete: ' + JSON.stringify(mediaImport))

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
// Optional: in the course, not among the certificate's required pools.
await asInstructor('POST', `/courses/${course.id}/pools`, { poolId: mediaPoolId, sortOrder: poolIds.length })
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
// GitHub #86 / #87: the mandatory-training profile asks for an open before a confirmation and caps
// the exam attempts per pool and cycle at 3; the IT course keeps neither (as before 5.16.0).
const openFirst = detail.open_before_confirm === true
const examAttemptLimit = detail.exam_attempt_limit ?? null
if (!itCourse && (!openFirst || examAttemptLimit !== 3)) throw new Error(`profile 'mandatory': open_before_confirm=${detail.open_before_confirm}, exam_attempt_limit=${detail.exam_attempt_limit} (expected true, 3)`)
if (itCourse && (openFirst || examAttemptLimit !== null)) throw new Error(`IT course: open_before_confirm=${detail.open_before_confirm}, exam_attempt_limit=${detail.exam_attempt_limit} (expected false, none)`)

// The administrator runs the course with the instructor (co-instructor). A Nextcloud admin who
// is not a member does not see the course in the list, and the student view is only meaningful
// for someone who leads the course.
await asInstructor('POST', `/courses/${course.id}/members`, { userId: 'admin', role: 'instructor' })

const members = await asInstructor('GET', `/courses/${course.id}/members`)
const memberIds = (Array.isArray(members) ? members : members.members || []).map((m) => m.user_id)
for (const uid of ['learner1', 'learner2', 'learner3']) {
	if (!memberIds.includes(uid)) throw new Error(`${uid} was not enrolled through the group: ${JSON.stringify(members).slice(0, 300)}`)
}

// GitHub #75: learning materials. Three Markdown files (no pdftotext needed) in the instructor's
// folder "Safety materials", linked and scanned through the app; two are ticked as required
// through the same endpoint as the Required column, the third stays optional. In the
// mandatory-training run the exam waits for the required content (gate on), so learner1 has to
// confirm both before the exam; the IT course leaves the gate off, as before.
const dav = async (method, path, body) => {
	const res = await fetch(`${state.URL}/remote.php/dav/files/instructor/${path}`, {
		method,
		headers: { Authorization: 'Basic ' + Buffer.from(`instructor:${state.INSTRUCTOR_PASS}`).toString('base64'), ...(body ? { 'Content-Type': 'text/markdown' } : {}) },
		body,
	})
	if (!res.ok) throw new Error(`WebDAV ${method} ${path} → ${res.status}`)
}
const MATERIAL_FOLDER = 'Safety materials'
const MATERIALS = [
	{ file: 'Evacuation plan.md', required: true, text: '# Evacuation plan\n\nLeave by the nearest marked exit. Meet at the assembly point in the car park. Do not use the lifts.\n' },
	{ file: 'Data protection policy.md', required: true, text: '# Data protection policy\n\nLock your screen when you leave your desk. Send personal data only through approved channels.\n' },
	{ file: 'First aid kit locations.md', required: false, text: '# First aid kit locations\n\nGround floor kitchen, first floor copy room, reception.\n' },
]
await dav('MKCOL', encodeURIComponent(MATERIAL_FOLDER))
for (const m of MATERIALS) await dav('PUT', `${encodeURIComponent(MATERIAL_FOLDER)}/${encodeURIComponent(m.file)}`, m.text)
await asInstructor('POST', `/courses/${course.id}/material-folder`, { folder: `/${MATERIAL_FOLDER}` })
const scanned = await asInstructor('POST', `/courses/${course.id}/documents/scan`)
const docByName = Object.fromEntries((Array.isArray(scanned) ? scanned : []).map((d) => [d.file_name, d]))
const missingDocs = MATERIALS.filter((m) => !docByName[m.file])
if (missingDocs.length) throw new Error(`scan did not find ${missingDocs.map((m) => m.file).join(', ')}: ${JSON.stringify(scanned).slice(0, 300)}`)
const requiredDocIds = MATERIALS.filter((m) => m.required).map((m) => docByName[m.file].id)
await asInstructor('PUT', `/courses/${course.id}/required-documents`, { documentIds: requiredDocIds, required: true })
const examGated = !itCourse
await asInstructor('PUT', `/courses/${course.id}/video-gate`, { enabled: examGated })
const requiredRows = (await asInstructor('GET', `/courses/${course.id}/videos`)) || []
if (requiredRows.length !== requiredDocIds.length) throw new Error(`expected ${requiredDocIds.length} required documents, the course has ${JSON.stringify(requiredRows).slice(0, 300)}`)

// GitHub #78: one logo in the administrator's library, chosen for the course. A 600 × 160 PNG
// made here (dark lettering-like bars on transparent ground, like a wordmark), no fixture file.
const LOGO_NAME = 'Wordmark'
const logoPng = makePng(600, 160)
const logoForm = new FormData()
logoForm.append('name', LOGO_NAME)
logoForm.append('logo', new Blob([logoPng], { type: 'image/png' }), 'wordmark.png')
const logoRes = await fetch(`${api}/settings/admin/cert-logos`, {
	method: 'POST',
	headers: { Authorization: 'Basic ' + Buffer.from(`admin:${state.ADMIN_PASS}`).toString('base64'), 'OCS-APIRequest': 'true', Accept: 'application/json' },
	body: logoForm,
})
const logoJson = await logoRes.json().catch(() => null)
if (!logoRes.ok || !logoJson?.added) throw new Error(`logo upload → ${logoRes.status}: ${JSON.stringify(logoJson).slice(0, 300)}`)
const logoId = logoJson.added
await asInstructor('PUT', `/courses/${course.id}/cert-layout/logo`, { logoId })
// GitHub #77: the organisation name on the certificate, set for this course only.
const ISSUER_NAME = 'Northwind Safety Ltd'
await asInstructor('PATCH', `/courses/${course.id}/cert-config`, { certLayout: { issuer_name: ISSUER_NAME } })
const layout = await asInstructor('GET', `/courses/${course.id}/cert-layout`)
if (layout?.issuer_name !== ISSUER_NAME) throw new Error(`issuer_name did not stick: ${JSON.stringify(layout).slice(0, 300)}`)

// GitHub #82: targets for the dead-link stations. An archived course with learner1 in it, a
// course with nobody but its instructor, a course that was created and deleted again (its id is a
// link that outlived its target) and a pool id that was never handed out. Only the instructor's and
// learner1's course lists gain a course; the generic sweeps (admin, learner2) see nothing new.
const archivedCourse = await asInstructor('POST', '/courses', { title: 'Safety training 2025', description: 'Last year\'s run.', profile: itCourse ? 'it' : 'mandatory' })
await asInstructor('POST', `/courses/${archivedCourse.id}/members`, { userId: 'learner1', role: 'student' })
await asInstructor('PUT', `/courses/${archivedCourse.id}`, { status: 'archived' })
const archivedCheck = await asInstructor('GET', `/courses/${archivedCourse.id}`)
if (archivedCheck.status !== 'archived') throw new Error(`course ${archivedCourse.id} did not archive: ${archivedCheck.status}`)
const privateCourse = await asInstructor('POST', '/courses', { title: 'Instructors only' })
const deletedCourse = await asInstructor('POST', '/courses', { title: 'Deleted course' })
await asInstructor('DELETE', `/courses/${deletedCourse.id}`)
const deadLinks = {
	archivedCourseId: archivedCourse.id,
	privateCourseId: privateCourse.id,
	deletedCourseId: deletedCourse.id,
	unknownPoolId: 999999,
}

// GitHub #97 / #98: a pool the administrator owns and shares with the instructor "View only". In
// "Add pool" it is greyed out with its reason, the server refuses it, and its question hit in the
// Pools search opens "View questions", not the editor. The "&" checks that the hit names the pool
// as typed (#98). Not added to any course: the course and the generic sweeps stay as they were.
const SHARED_POOL_NAME = 'Q&A reference'
const sharedImport = await asAdmin('POST', '/pools/import', {
	jsonData: JSON.stringify({ pools: [{ name: SHARED_POOL_NAME, description: 'Read-only reference questions from the administrator.', questions: [
		{ text: 'Which number do you call for the building security desk?', answers: [{ text: 'The number on the badge', is_correct: true }, { text: 'Any colleague', is_correct: false }] },
		{ text: 'Where is the reference binder kept?', answers: [{ text: 'At reception', is_correct: true }, { text: 'In the car park', is_correct: false }] },
	] }] }),
	dry_run: false,
	file_name: 'shared-reference.json',
})
const sharedPoolId = sharedImport.pools?.[0]?.id
if (!sharedPoolId || sharedImport.imported_questions !== 2) throw new Error('shared pool import incomplete: ' + JSON.stringify(sharedImport))
await asAdmin('POST', `/pools/${sharedPoolId}/shares`, { sharedWith: 'instructor', shareType: 'user', permission: 'read' })
const sharedWithInstructor = await asInstructor('GET', '/shared')
const sharedRow = (Array.isArray(sharedWithInstructor) ? sharedWithInstructor : sharedWithInstructor?.pools || []).find((p) => Number(p.poolId ?? p.pool_id) === sharedPoolId && p.permission === 'read')
if (!sharedRow) throw new Error(`the view-only pool ${sharedPoolId} is not shared with the instructor: ${JSON.stringify(sharedWithInstructor).slice(0, 300)}`)

const answers = {}
for (const pool of JSON.parse(poolsDoc).pools) {
	for (const q of pool.questions) answers[q.text] = q.answers.find((a) => a.is_correct).text
}
answers[mediaQuestion.text] = mediaQuestion.answers.find((a) => a.is_correct).text
// poolOwner: the only persona whose pool list must show the pools (nothing is shared).
writeFileSync(join(dirname(process.env.PERSONA_STATE), 'seed.json'), JSON.stringify({
	courseId: course.id, poolIds, poolOwner: 'instructor', modeConfig, deadLinks,
	materials: { folder: MATERIAL_FOLDER, required: MATERIALS.filter((m) => m.required).map((m) => m.file), optional: MATERIALS.filter((m) => !m.required).map((m) => m.file), examGated, openFirst },
	examAttemptLimit,
	certificate: { issuerName: ISSUER_NAME, logoId, logoName: LOGO_NAME },
	sharedPool: { id: sharedPoolId, name: SHARED_POOL_NAME, owner: 'admin', permission: 'read', question: 'Where is the reference binder kept?' },
	media: { poolId: mediaPoolId, poolName: fixturePool.name, examKey: MEDIA_KEY, question: mediaQuestion.text, wrongAnswer: mediaQuestion.answers.find((a) => !a.is_correct).text },
	gamification: itCourse, ai: admin.ai_enabled === 'yes' ? `${admin.ai_provider} (no backend answers)` : 'off',
	answers,
}, null, 2))
console.log(`seed: course ${course.id}, pools ${poolIds.join(', ')}, media pool ${mediaPoolId}, AI ${admin.ai_enabled}, mode_config ${JSON.stringify(modeConfig)}`)

/**
 * A w × h RGBA PNG: transparent ground with dark bars, roughly a wordmark (GitHub #78 seed logo).
 * Built here so that the seed needs no image file; zlib and a CRC-32 are all a PNG needs.
 */
function makePng(w, h) {
	const crcTable = Array.from({ length: 256 }, (_, n) => {
		let c = n
		for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
		return c >>> 0
	})
	const crc = (buf) => {
		let c = 0xffffffff
		for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8)
		return (c ^ 0xffffffff) >>> 0
	}
	const chunk = (type, data) => {
		const len = Buffer.alloc(4); len.writeUInt32BE(data.length)
		const td = Buffer.concat([Buffer.from(type, 'ascii'), data])
		const c = Buffer.alloc(4); c.writeUInt32BE(crc(td))
		return Buffer.concat([len, td, c])
	}
	const ihdr = Buffer.alloc(13)
	ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4)
	ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0
	const raw = Buffer.alloc((w * 4 + 1) * h)
	for (let y = 0; y < h; y++) {
		const row = y * (w * 4 + 1)
		raw[row] = 0
		for (let x = 0; x < w; x++) {
			const ink = y > 30 && y < 130 && (Math.floor(x / 22) % 3 !== 2) && x > 20 && x < w - 20
			const o = row + 1 + x * 4
			raw[o] = 0x1a; raw[o + 1] = 0x3a; raw[o + 2] = 0x5c; raw[o + 3] = ink ? 255 : 0
		}
	}
	return Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
		chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0)),
	])
}
