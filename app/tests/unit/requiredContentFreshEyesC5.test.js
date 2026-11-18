/**
 * Fresh-eyes C5 (persona "off": gamification, AI and Leitner off, mandatory training):
 * - the exam lock note and the instructor's gate hint named Leitner in a course with Leitner off;
 * - learners saw no count of the required content, and "Open" next to "Open document" meant
 *   "not confirmed yet";
 * - an instructor in the student view could confirm a required document (the server half is
 *   DocumentConfirmControllerTest);
 * - "Completion & certificate" did not name the required content the exam waits for;
 * - the stale "Required content" tab name in the exam's lock message.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'learner' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), getFilePickerBuilder: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
for (const name of ['CourseKnowledgeImport', 'CourseMaterials', 'AuthFlowSimulator', 'DnsResolver', 'ExamMode',
	'FirewallBuilder', 'KnowledgeModeration', 'LeitnerMode', 'NatTable', 'PortScanner', 'RoutingTable',
	'SubnetCalculator', 'TrainingMode', 'WiresharkLite', 'Certificate', 'AiConsentHint']) {
	vi.doMock(`../../src/components/${name}.vue`, () => stub(name))
}

const axios = (await import('@nextcloud/axios')).default
const CourseTabLernraum = (await import('../../src/components/CourseTabLernraum.vue')).default
const RequiredContentSettings = (await import('../../src/components/RequiredContentSettings.vue')).default
const CourseSummary = (await import('../../src/components/CourseSummary.vue')).default
const { practiseModesOpen } = await import('../../src/utils/requiredContent.js')
const { requiredContentMessage } = await import('../../src/utils/modeGate.js')

const course = (modeConfig) => ({ video_gate_enabled: true, mode_config: modeConfig })

let app = null
let host = null
afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
	vi.clearAllMocks()
})

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

function mount(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
}

describe('the practising modes a gated course keeps open', () => {
	it('follows mode_config: a mode is on unless switched off', () => {
		expect(practiseModesOpen(course({}))).toEqual({ training: true, leitner: true })
		expect(practiseModesOpen(course({ leitner: false }))).toEqual({ training: true, leitner: false })
		expect(practiseModesOpen(course({ training: false, leitner: false }))).toEqual({ training: false, leitner: false })
		expect(practiseModesOpen(null)).toEqual({ training: true, leitner: true })
	})

	it('the learner lock note names Leitner only when the course has it on', () => {
		const note = (modeConfig) => CourseTabLernraum.computed.examLockNote.call({ course: course(modeConfig) })
		// IT courses with Leitner on keep the wording they had
		expect(note({ training: true, leitner: true })).toBe('Die Prüfung ist gesperrt, bis du alle Pflichtinhalte abgeschlossen hast. Training und Leitner stehen dir schon offen.')
		expect(note({ training: true, leitner: false })).toBe('Die Prüfung ist gesperrt, bis du alle Pflichtinhalte abgeschlossen hast. Training steht dir schon offen.')
		expect(note({ training: false, leitner: true })).toBe('Die Prüfung ist gesperrt, bis du alle Pflichtinhalte abgeschlossen hast. Leitner steht dir schon offen.')
		expect(note({ training: false, leitner: false })).toBe('Die Prüfung ist gesperrt, bis du alle Pflichtinhalte abgeschlossen hast.')
	})

	it('the instructor gate hint names Leitner only when the course has it on', () => {
		const hint = (modeConfig) => RequiredContentSettings.computed.gateHint.call({ course: course(modeConfig) })
		expect(hint({})).toBe('Gesperrt wird nur die Prüfung, die für das Zertifikat zählt. Training, Leitner und Probeprüfungen bleiben offen.')
		expect(hint({ leitner: false })).toBe('Gesperrt wird nur die Prüfung, die für das Zertifikat zählt. Training und Probeprüfungen bleiben offen.')
		expect(hint({ training: false })).toBe('Gesperrt wird nur die Prüfung, die für das Zertifikat zählt. Leitner und Probeprüfungen bleiben offen.')
		expect(hint({ training: false, leitner: false })).toBe('Gesperrt wird nur die Prüfung, die für das Zertifikat zählt. Probeprüfungen bleiben offen.')
	})

	it('both templates read the computed text, not a fixed sentence', () => {
		const lernraum = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		expect(lernraum).toMatch(/data-test="required-content-locked">\s*\{\{ examLockNote \}\}/)
		const settings = readFileSync(resolve(__dirname, '../../src/components/RequiredContentSettings.vue'), 'utf8')
		expect(settings).toMatch(/data-test="required-content-gate-hint">\s*\{\{ gateHint \}\}/)
	})
})

describe('learner view of the required content', () => {
	function lernraum(overrides = {}) {
		const instance = {
			course: { is_instructor: false, video_gate_enabled: true, mode_config: {} },
			isInstructor: false,
			normalizedCourseVideos: [{ id: 1, sourceType: 'document' }, { id: 2, sourceType: 'document' }, { id: 3, sourceType: 'vimeo' }],
			videoStatus: { 1: { completed: true } },
			documentPending: {},
			...overrides,
		}
		instance.isItemComplete = (id) => instance.videoStatus[id]?.completed === true
		instance.courseId = 7
		instance.documentUrl = (item) => CourseTabLernraum.methods.documentUrl.call(instance, item)
		instance.setItemStatus = vi.fn()
		instance.$emit = vi.fn()
		Object.defineProperty(instance, 'previewsAsStudent', { get: () => CourseTabLernraum.computed.previewsAsStudent.call(instance) })
		return instance
	}

	it('counts the completed items', () => {
		expect(CourseTabLernraum.computed.requiredContentProgress.call(lernraum())).toEqual({ done: 1, total: 3 })
		const template = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		expect(template).toContain("t('learning', 'Abgeschlossen: {done} von {total}', requiredContentProgress)")
	})

	it('labels a pending item "not completed yet", not "open" next to "Open document"', () => {
		const template = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		expect(template).toContain("t('learning', '✓ Abgeschlossen') : t('learning', 'Noch nicht abgeschlossen')")
		expect(template).not.toContain("t('learning', '✓ Abgeschlossen') : t('learning', 'Offen')")
	})

	it('an instructor in the student view confirms nothing', async () => {
		const preview = lernraum({ course: { is_instructor: true, video_gate_enabled: true, mode_config: {} }, isInstructor: false })
		expect(preview.previewsAsStudent).toBe(true)
		await CourseTabLernraum.methods.markDocumentRead.call(preview, { id: 2, sourceType: 'document', documentId: 12 })
		expect(axios.post).not.toHaveBeenCalled()

		const learner = lernraum()
		expect(learner.previewsAsStudent).toBe(false)
		axios.post.mockResolvedValueOnce({ data: { completed: true } })
		await CourseTabLernraum.methods.markDocumentRead.call(learner, { id: 2, sourceType: 'document', documentId: 12 })
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/video-progress/document-read', { contentId: 2 })
		expect(learner.$emit).not.toHaveBeenCalledWith('error', expect.anything())
	})

	/**
	 * GitHub #75 (review M1, pre-existing): "Confirm I have read this" was active without the
	 * "Open document" link, so a learner confirmed a file nobody could open.
	 */
	it('a document without a link to open it is not confirmed, and the page says why', async () => {
		const learner = lernraum()
		await CourseTabLernraum.methods.markDocumentRead.call(learner, { id: 2, sourceType: 'document', documentId: null })
		expect(axios.post).not.toHaveBeenCalled()

		const template = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		expect(template).toContain('documentPending[item.id] || !documentUrl(item) || needsOpen(item)"')
		expect(template).toMatch(/v-if="!documentUrl\(item\) && !isItemComplete\(item\.id\)"\s*:id="'document-unavailable-' \+ item\.id"/)
	})

	it('a 409 document_unavailable says that nothing was confirmed', async () => {
		const learner = lernraum()
		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { code: 'document_unavailable' } } })
		await CourseTabLernraum.methods.markDocumentRead.call(learner, { id: 2, sourceType: 'document', documentId: 12 })
		expect(learner.$emit).toHaveBeenCalledWith('error', expect.stringContaining('Es wurde nichts bestätigt'))

		axios.post.mockRejectedValueOnce({ response: { status: 500, data: {} } })
		await CourseTabLernraum.methods.markDocumentRead.call(learner, { id: 2, sourceType: 'document', documentId: 12 })
		expect(learner.$emit).toHaveBeenLastCalledWith('error', 'Dokument konnte nicht als gelesen markiert werden.')
	})

	it('the confirm button is disabled in the preview and the page says why', () => {
		const template = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		expect(template).toContain(':disabled="previewsAsStudent || isItemComplete(item.id) || documentPending[item.id] || !documentUrl(item) || needsOpen(item)"')
		expect(template).toMatch(/v-if="previewsAsStudent"[^>]*data-test="required-content-preview"/)
	})

	it('the exam lock message names the tab that holds the required content now', () => {
		const message = requiredContentMessage({ response: { status: 403, data: { gate: 'video' } } })
		expect(message).toContain('„Lernmaterial“')
		expect(message).not.toContain('„Pflichtinhalte“')
	})
})

describe('Completion & certificate names the required content', () => {
	function serve(videos) {
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/courses/7/pass-status') {
				return Promise.resolve({ data: { applicable: true, passed: false, score: null, threshold: 75, requirement: 'any_exam' } })
			}
			if (url === '/apps/learning/api/video-progress/course/7') {
				return Promise.resolve({ data: { videos } })
			}
			return Promise.resolve({ data: {} })
		})
	}

	it('lists the required content with its count when the course locks the exam', async () => {
		serve([{ id: 1, completed: true }, { id: 2, completed: false }])
		mount(CourseSummary, { courseId: 7, course: { cert_enabled: true, video_gate_enabled: true, mode_config: { course_summary: false } } })
		await flush()
		expect(host.querySelector('[data-test="cert-required-content"]')?.textContent).toContain('Pflichtinhalte im Reiter „Lernmaterial“')
		expect(host.querySelector('[data-test="cert-required-content-progress"]')?.textContent.trim()).toBe('Abgeschlossen: 1 von 2')
	})

	it('says nothing about it without the lock, and asks for nothing', async () => {
		serve([{ id: 1, completed: false }])
		mount(CourseSummary, { courseId: 7, course: { cert_enabled: true, video_gate_enabled: false, mode_config: { course_summary: false } } })
		await flush()
		expect(host.querySelector('[data-test="cert-required-content"]')).toBeNull()
		expect(axios.get).not.toHaveBeenCalledWith('/apps/learning/api/video-progress/course/7')
	})

	it('keeps the sentence without a count when the status cannot be read', async () => {
		serve([])
		axios.get.mockImplementation((url) => (url.includes('video-progress')
			? Promise.reject(new Error('down'))
			: Promise.resolve({ data: { applicable: true, passed: false, score: null, threshold: 75, requirement: 'any_exam' } })))
		mount(CourseSummary, { courseId: 7, course: { cert_enabled: true, video_gate_enabled: true, mode_config: { course_summary: false } } })
		await flush()
		expect(host.querySelector('[data-test="cert-required-content"]')).not.toBeNull()
		expect(host.querySelector('[data-test="cert-required-content-progress"]')).toBeNull()
	})
})
