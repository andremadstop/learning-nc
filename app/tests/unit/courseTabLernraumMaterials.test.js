/**
 * The real CourseTabLernraum hands the learning materials what they need:
 * - GitHub #89: whether "Exam only after all required content is completed" is off, so the
 *   "Required" column can say that its marks lock nothing;
 * - GitHub #90 (U2): learners no longer receive the material folder path, only whether one is
 *   linked; the optional files must still be shown to them.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

const { api } = vi.hoisted(() => ({ api: { videos: [] } }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (String(url).includes('/videos')) return Promise.resolve({ data: api.videos })
			return Promise.resolve({ data: [] })
		}),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const { slotStub, emptyStub } = vi.hoisted(() => ({
	slotStub: (name, tag = 'div') => ({ default: { name, template: `<${tag}><slot /></${tag}>` } }),
	emptyStub: (name) => ({ default: { name, template: '<div />' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'button'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => slotStub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => slotStub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => emptyStub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => slotStub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard'))
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => emptyStub('CourseKnowledgeImport'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => emptyStub('KnowledgeModeration'))
vi.mock('../../src/components/RequiredContentSettings.vue', () => emptyStub('RequiredContentSettings'))
vi.mock('../../src/components/TrainingPrivacyNotice.vue', () => emptyStub('TrainingPrivacyNotice'))
vi.mock('../../src/components/CourseMaterials.vue', () => ({
	default: {
		name: 'CourseMaterials',
		props: ['courseId', 'isInstructor', 'aiEnabled', 'excludeDocumentIds', 'optionalHint', 'hideEmpty', 'examGateOff'],
		template: '<pre class="materials-props">{{ JSON.stringify({ isInstructor, optionalHint: !!optionalHint, examGateOff: !!examGateOff }) }}</pre>',
	},
}))

import CourseTabLernraum from '../../src/components/CourseTabLernraum.vue'

let app = null
let host = null

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mountMaterials(course, actsAsInstructor) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(CourseTabLernraum, {
			courseId: 7, course, coursePools: [], allPools: [], activeTab: 'materials', userRole: actsAsInstructor ? 'instructor' : 'student', actsAsInstructor, contentLanguage: 'de',
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return [...host.querySelectorAll('.materials-props')].map((el) => JSON.parse(el.textContent))
}

afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	api.videos = []
})

describe('CourseTabLernraum — learning materials wiring', () => {
	it('tells the instructor table that the exam switch is off (GitHub #89)', async () => {
		const [props] = await mountMaterials({ id: 7, material_folder: '/Kurs', video_gate_enabled: false }, true)
		expect(props).toEqual({ isInstructor: true, optionalHint: false, examGateOff: true })
	})

	it('and that it is on', async () => {
		const [props] = await mountMaterials({ id: 7, material_folder: '/Kurs', video_gate_enabled: true }, true)
		expect(props.examGateOff).toBe(false)
	})
})
