/**
 * GitHub #86: "Confirm I have read this" was active before the learner had opened the document,
 * so a whole course could be confirmed in seconds. In a course with open_before_confirm the
 * button now waits for an open of this version (the server records it on document#download and
 * refuses a confirmation without one, RequiredDocumentServiceTest), says why, and reads the state
 * again after the learner clicked "Open document". Mounts the real CourseTabLernraum.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'learner' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), getFilePickerBuilder: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
for (const name of ['CourseKnowledgeImport', 'CourseMaterials', 'AuthFlowSimulator', 'DnsResolver', 'ExamMode',
	'FirewallBuilder', 'KnowledgeModeration', 'LeitnerMode', 'NatTable', 'PortScanner', 'RoutingTable',
	'SubnetCalculator', 'TrainingMode', 'WiresharkLite', 'Certificate', 'AiConsentHint', 'VideoPlayer', 'VideoConsentOverlay']) {
	vi.doMock(`../../src/components/${name}.vue`, () => stub(name))
}

// GitHub #110: the reader's own request records the open; the stub reports "loaded" like it
vi.doMock('../../src/components/MaterialReader.vue', () => ({
	__esModule: true,
	default: { name: 'MaterialReader', props: ['url', 'fileName'], emits: ['loaded', 'close'], mounted() { if (!globalThis.__readerNoLoad) this.$emit('loaded') }, template: '<div data-test="material-reader" :data-url="url"><button data-test="reader-close" @click="$emit(\'close\')" /></div>' },
}))

const axios = (await import('@nextcloud/axios')).default
const CourseTabLernraum = (await import('../../src/components/CourseTabLernraum.vue')).default

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
	for (let i = 0; i < 8; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

const row = (extra) => ({ id: 2, source_type: 'document', title: 'Richtlinie', sort_order: 0, covered_pct: 0, completed: false, document_id: 12, updated: false, confirm_again: false, ...extra })

async function mountLernraum(rows, course = {}) {
	let current = rows
	const errors = []
	axios.get.mockImplementation((url) => Promise.resolve({ data: url.includes('/video-progress/course/') ? { videos: current() } : {} }))
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(CourseTabLernraum, {
			courseId: 7,
			course: { id: 7, is_instructor: false, video_gate_enabled: true, open_before_confirm: true, mode_config: {}, ...course },
			userRole: 'student',
			actsAsInstructor: false,
			activeTab: 'materials',
			onError: (message) => errors.push(message),
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return {
		button: () => host.querySelector('[data-test="document-confirm-2"]'),
		hint: () => host.querySelector('[data-test="document-open-first-2"]'),
		link: () => host.querySelector('[data-test="document-open-2"]'),
		setRows: (next) => { current = next },
		errors,
	}
}

describe('GitHub #86: confirm only after opening', () => {
	it('keeps the button disabled with a hint until the document was opened', async () => {
		const page = await mountLernraum(() => [row({ open_required: true, opened: false })])
		expect(page.button()).not.toBeNull()
		expect(page.button().disabled).toBe(true)
		expect(page.hint().textContent).toContain('Öffne das Dokument zuerst')
		expect(page.button().getAttribute('aria-describedby')).toBe('document-open-first-2')
	})

	it('reads the state again after "Open document" and enables the button', async () => {
		const page = await mountLernraum(() => [row({ open_required: true, opened: false })])
		page.setRows(() => [row({ open_required: true, opened: true })])
		page.link().addEventListener('click', (e) => e.preventDefault()) // no navigation in the test DOM
		page.link().dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
		// the learner comes back from the tab with the document
		window.dispatchEvent(new Event('focus'))
		await flush()
		expect(page.button().disabled).toBe(false)
		expect(page.hint()).toBeNull()
	})

	it('leaves a course without the option as before', async () => {
		const page = await mountLernraum(() => [row({ open_required: false, opened: false })], { open_before_confirm: false })
		expect(page.button().disabled).toBe(false)
		expect(page.hint()).toBeNull()
	})

	it('a 409 document_not_opened says so and reads the state again', async () => {
		const page = await mountLernraum(() => [row({ open_required: false, opened: false })], { open_before_confirm: false })
		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { code: 'document_not_opened' } } })
		const before = axios.get.mock.calls.length
		page.button().click()
		await flush()
		expect(page.errors).toEqual(['Öffne das Dokument zuerst. Danach kannst du es als gelesen bestätigen.'])
		expect(axios.get.mock.calls.length).toBeGreaterThan(before)
	})

	it('GitHub #110: a required Markdown document opens in the reader and its open enables the button', async () => {
		const page = await mountLernraum(() => [row({ open_required: true, opened: false, document_type: 'markdown' })])
		page.setRows(() => [row({ open_required: true, opened: true, document_type: 'markdown' })])
		const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 })
		page.link().dispatchEvent(event)
		await flush()
		expect(event.defaultPrevented).toBe(true)
		const reader = host.querySelector('[data-test="material-reader"]')
		expect(reader).not.toBeNull()
		expect(reader.getAttribute('data-url')).toBe('/apps/learning/api/courses/7/documents/12/download')
		// no focus event: the reader's "loaded" alone reads the state again
		expect(page.button().disabled).toBe(false)
	})

	it('GitHub #110: a required PDF still opens in a new tab', async () => {
		const page = await mountLernraum(() => [row({ open_required: true, opened: false, document_type: 'pdf' })])
		page.link().addEventListener('click', (e) => e.preventDefault())
		const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 })
		page.link().dispatchEvent(event)
		await flush()
		expect(host.querySelector('[data-test="material-reader"]')).toBeNull()
	})

	it('GitHub #110: closing the reader before it loaded still reads the state again', async () => {
		globalThis.__readerNoLoad = true
		try {
			const page = await mountLernraum(() => [row({ open_required: true, opened: false, document_type: 'markdown' })])
			page.setRows(() => [row({ open_required: true, opened: true, document_type: 'markdown' })])
			page.link().dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }))
			await flush()
			expect(page.button().disabled).toBe(true)
			host.querySelector('[data-test="reader-close"]').click()
			await flush()
			expect(host.querySelector('[data-test="material-reader"]')).toBeNull()
			expect(page.button().disabled).toBe(false)
		} finally {
			globalThis.__readerNoLoad = false
		}
	})
})
