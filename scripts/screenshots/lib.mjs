// screenshots/lib.mjs — what the screenshot run takes and how big it may be (GitHub #74).
// Pure data and checks, shared by shots.spec.cjs (through shots.config.cjs), optimise.mjs and the
// unit test app/tests/unit/screenshots74.test.js.

/** Browser window of every picture: light theme, English (en-GB), device pixel ratio 2. */
export const VIEWPORT = { width: 1440, height: 900 }
export const DEVICE_SCALE_FACTOR = 2
/** Each optimised PNG stays below this (README and App Store load them as they are). */
export const BUDGET_BYTES = 400 * 1024

/** The example question of examples/app-tour the Training picture shows (it has intro and dialog). */
export const TOUR = { poolIndex: 0, examKey: 'app-tour-11-en', folder: 'app-tour-11', bundleLang: 'en' }

/**
 * Every picture: `id` is the raw file name the spec writes (out/raw/<id>.png), `dest` where the
 * optimised copy goes, relative to the repository root. Gallery pictures (README, App Store) go to
 * screenshots/, the ones only the mandatory-training guide uses to docs/guides/img/.
 */
export const SHOTS = [
	{ id: 'create-course', dest: 'screenshots/12-create-course-profile.png', what: 'Create course dialog with the profile "Mandatory training with certificate"' },
	{ id: 'learner-materials', dest: 'screenshots/13-required-materials.png', what: 'Learning materials of a learner: required documents with "Confirm I have read this", optional files below' },
	{ id: 'question-feedback', dest: 'screenshots/14-question-feedback.png', what: 'Training: an example question answered, feedback per option, key takeaway and the dialog player' },
	{ id: 'course-rules', dest: 'screenshots/15-course-rules-certification.png', what: 'Course rules › Certification: pass mark, required pools, validity in years' },
	{ id: 'certificate', dest: 'screenshots/16-certificate.png', what: 'A certificate with organisation name and logo, QR code and certificate ID' },
	{ id: 'verify', dest: 'screenshots/17-certificate-verification.png', what: 'The public verification page of that certificate, without login' },
	{ id: 'compliance', dest: 'screenshots/18-compliance-coverage.png', what: 'Compliance report and coverage of all enrolled learners' },
	{ id: 'pool-import', dest: 'screenshots/19-pool-import.png', what: 'Import pools: the dry run of examples/app-tour/pool.json' },
	{ id: 'admin-logos', dest: 'docs/guides/img/mandatory-training-admin-logos.png', what: 'Administration › Learning › Logos for certificates' },
	{ id: 'instructor-materials', dest: 'docs/guides/img/mandatory-training-instructor-materials.png', what: 'Learning materials of the instructor: the Required column and the switches below' },
	{ id: 'certificate-design', dest: 'docs/guides/img/mandatory-training-certificate-design.png', what: 'Certificate design: organisation name and logo choice' },
	{ id: 'exam-start', dest: 'docs/guides/img/mandatory-training-exam-start.png', what: 'The counting exam before the start' },
	{ id: 'completion', dest: 'docs/guides/img/mandatory-training-completion.png', what: 'Completion & certificate of a learner who passed' },
]

/** Width and height from a PNG's IHDR chunk, or null when the buffer is not a PNG. */
export function pngSize(buf) {
	const sig = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]
	if (!buf || buf.length < 24 || sig.some((b, i) => buf[i] !== b)) return null
	if (buf.toString('latin1', 12, 16) !== 'IHDR') return null
	return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) }
}

/**
 * What is wrong with the optimised pictures: one message per problem, empty when all is well.
 * `files` maps a SHOTS id to { bytes, buf } of its optimised file, or to undefined when missing.
 */
export function budgetFindings(files, { shots = SHOTS, budget = BUDGET_BYTES, viewport = VIEWPORT, scale = DEVICE_SCALE_FACTOR } = {}) {
	const out = []
	for (const shot of shots) {
		const f = files[shot.id]
		if (!f) { out.push(`${shot.id}: missing`); continue }
		const size = pngSize(f.buf)
		if (!size) { out.push(`${shot.id}: not a PNG`); continue }
		if (f.bytes > budget) out.push(`${shot.id}: ${Math.round(f.bytes / 1024)} KB, over the ${Math.round(budget / 1024)} KB budget`)
		if (size.width !== viewport.width * scale) out.push(`${shot.id}: ${size.width} px wide, expected ${viewport.width * scale}`)
		if (size.height < viewport.height * scale) out.push(`${shot.id}: ${size.height} px high, less than the window (${viewport.height * scale})`)
	}
	return out
}
