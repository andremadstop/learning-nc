/**
 * GitHub #92 (comment 18.11. 11:38), the instructor's Pools tab of a course (CourseTabLernraum,
 * mounted for real): an empty course shows "Pools hinzufügen" right under "No pools assigned",
 * opening the same dialog as the button at the top; learners do not get it.
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

describe('empty course: "Pools hinzufügen" under "No pools assigned" (GitHub #92)', () => {
	it('an instructor gets a primary button that opens the add-pool dialog', async () => {
		await mount()
		const button = host.querySelector('.nc-empty-content .empty-add-pool-btn')
		expect(button).not.toBeNull()
		expect(button.classList.contains('primary')).toBe(true)
		expect(button.textContent).toContain('Pools hinzufügen')
		expect(host.querySelector('.nc-modal')).toBeNull()
		button.click()
		await flush()
		expect(host.querySelector('.nc-modal')).not.toBeNull()
		expect(axios.get).toHaveBeenCalledWith(POOLS_URL)
	})

	it('a course with pools has no such button', async () => {
		await mount({ coursePools: [{ pool_id: 1, pool_name: 'Eigener Pool', question_count: 3, sort_order: 0 }] })
		expect(host.querySelector('.empty-add-pool-btn')).toBeNull()
	})

	it('a learner (or the student view) never gets it', async () => {
		await mount({ instructor: false })
		expect(host.querySelector('.empty-add-pool-btn')).toBeNull()
	})
})
