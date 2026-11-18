/**
 * GitHub #97, the instructor's "Add pool" dialog of a course (CourseTabLernraum, mounted for real):
 * it offered pools shared "View only", and adding one failed with "Failed to add pool". They are
 * greyed out now, cannot be selected, carry the "View only" tag and the reason; a refusal for
 * another reason names it from the server's code.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const { slotStub, emptyStub } = vi.hoisted(() => ({
	slotStub: (name, tag = 'div', cls = '') => ({ default: { name, template: `<${tag} class="${cls}"><slot /><slot name="description" /></${tag}>`, props: ['name'] } }),
	emptyStub: (name) => ({ default: { name, template: '<div />' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', props: ['variant', 'disabled'], emits: ['click'], template: '<button :class="variant" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => slotStub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: { name: 'NcEmptyContent', props: ['name'], template: '<div class="nc-empty-content">{{ name }}<slot name="description" /><div class="empty-action"><slot name="action" /></div></div>' },
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => emptyStub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => ({
	default: { name: 'NcModal', props: ['show', 'size'], template: '<div class="nc-modal"><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard', 'div', 'nc-note-card'))
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => emptyStub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => emptyStub('CourseMaterials'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => emptyStub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => emptyStub('LeitnerMode'))
vi.mock('../../src/components/TrainingMode.vue', () => emptyStub('TrainingMode'))
vi.mock('../../src/components/TrainingPrivacyNotice.vue', () => emptyStub('TrainingPrivacyNotice'))
vi.mock('../../src/components/ExamMode.vue', () => emptyStub('ExamMode'))

import axios from '@nextcloud/axios'
import CourseTabLernraum from '../../src/components/CourseTabLernraum.vue'

const POOLS_URL = '/apps/learning/api/pools'
const ADD_URL = '/apps/learning/api/courses/7/pools'

let app = null
let host = null
let emitted = []

async function flush() {
	for (let i = 0; i < 8; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

async function mount({ coursePools = [], instructor = true } = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	emitted = []
	app = createApp({
		render: () => h(CourseTabLernraum, {
			courseId: 7,
			course: { is_instructor: instructor, mode_config: { training: true, leitner: true, exam: true } },
			coursePools,
			allPools: [],
			activeTab: instructor ? 'pools' : 'training',
			userRole: instructor ? 'instructor' : 'student',
			actsAsInstructor: instructor,
			contentLanguage: 'de',
			'onRefreshCourseDetail': () => emitted.push('refresh-course-detail'),
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
}

const OWN = { id: 1, name: 'Eigener Pool' }
const EDIT = { id: 2, name: 'Bearbeitbar geteilt', is_shared: true, permission: 'edit' }
const READ = { id: 3, name: 'Nur lesbar geteilt', is_shared: true, permission: 'read' }

function item(name) {
	return [...host.querySelectorAll('.pool-select-item')].find((el) => el.textContent.includes(name))
}

async function openDialog() {
	host.querySelector('.add-pool-btn').click()
	await flush()
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.get.mockImplementation((url) => {
		if (url === POOLS_URL) {
			return Promise.resolve({ data: { own: [OWN], shared: [EDIT, READ] } })
		}
		return Promise.resolve({ data: [] })
	})
})

afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
})

describe('add-pool dialog: view-only pools (GitHub #97)', () => {
	it('a view-only pool is greyed out, tagged and explained; own and edit pools are not', async () => {
		await mount()
		await openDialog()
		const read = item(READ.name)
		expect(read.classList.contains('view-only')).toBe(true)
		expect(read.getAttribute('aria-disabled')).toBe('true')
		expect(read.querySelector('.pool-view-only-badge').textContent).toContain('View only')
		const reason = read.querySelector('.pool-view-only-reason')
		expect(reason.textContent).toContain('Nur Besitzer oder Bearbeitende')
		expect(read.getAttribute('aria-describedby')).toBe(reason.id)
		for (const name of [OWN.name, EDIT.name]) {
			const el = item(name)
			expect(el.classList.contains('view-only')).toBe(false)
			expect(el.getAttribute('aria-disabled')).toBeNull()
			expect(el.querySelector('.pool-view-only-badge')).toBeNull()
		}
	})

	it('a view-only pool keeps the main background (grey text stays at 4.5:1)', async () => {
		const { readFileSync } = await import('node:fs')
		const { resolve } = await import('node:path')
		const source = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		const style = source.slice(source.indexOf('<style')).replace(/\/\*[\s\S]*?\*\//g, '')
		const rule = style.match(/\.pool-select-item\.view-only\s*\{([^}]*)\}/)
		expect(rule).not.toBeNull()
		expect(rule[1]).not.toMatch(/background|opacity/)
	})

	it('a view-only pool cannot be selected by click, Enter or Space', async () => {
		await mount()
		await openDialog()
		const read = item(READ.name)
		read.click()
		read.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
		read.dispatchEvent(new KeyboardEvent('keydown', { key: ' ' }))
		await flush()
		expect(read.getAttribute('aria-checked')).toBe('false')
		expect(host.querySelector('.pool-add-confirm')).toBeNull()
		item(EDIT.name).click()
		await flush()
		expect(item(EDIT.name).getAttribute('aria-checked')).toBe('true')
	})

	it('a refusal names its reason from the server code instead of "Failed to add pool"', async () => {
		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'Failed to add pool', code: 'already_added' } } })
		await mount()
		await openDialog()
		item(EDIT.name).click()
		await flush()
		host.querySelector('.pool-add-confirm button').click()
		await flush()
		const error = host.querySelector('.modal-error').textContent
		expect(error).toContain('„Bearbeitbar geteilt“')
		expect(error).toContain('Der Pool ist schon in diesem Kurs.')
		expect(error).not.toContain('Failed to add pool')
	})

	it.each([
		['pool_view_only', 'Nur Besitzer oder Bearbeitende'],
		['pool_not_found', 'Den Pool gibt es nicht mehr'],
		['no_permission', 'Nur Lehrkräfte dieses Kurses'],
		['course_not_found', 'Den Kurs gibt es nicht mehr'],
	])('code %s becomes a sentence', async (code, text) => {
		axios.post.mockRejectedValueOnce({ response: { status: 403, data: { error: 'x', code } } })
		await mount()
		await openDialog()
		item(OWN.name).click()
		await flush()
		host.querySelector('.pool-add-confirm button').click()
		await flush()
		expect(host.querySelector('.modal-error').textContent).toContain(text)
	})

	it('pools added before a failure leave the selection and the course reloads', async () => {
		axios.post
			.mockResolvedValueOnce({ data: {} })
			.mockRejectedValueOnce({ response: { status: 404, data: { code: 'pool_not_found' } } })
		await mount()
		await openDialog()
		item(OWN.name).click()
		item(EDIT.name).click()
		await flush()
		host.querySelector('.pool-add-confirm button').click()
		await flush()
		expect(axios.post).toHaveBeenCalledTimes(2)
		expect(axios.post.mock.calls[0][0]).toBe(ADD_URL)
		expect(emitted).toContain('refresh-course-detail')
		expect(item(OWN.name).getAttribute('aria-checked')).toBe('false')
		expect(item(EDIT.name).getAttribute('aria-checked')).toBe('true')
		expect(host.querySelector('.modal-error').textContent).toContain('„Bearbeitbar geteilt“')
	})
})
