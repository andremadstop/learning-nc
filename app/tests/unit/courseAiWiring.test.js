/**
 * GitHub #35: the admin's AI switch travels CourseDetail -> CourseTabLernraum -> CourseMaterials and
 * CourseDetail -> CourseTabKommunikation as the `aiEnabled` prop. Every receiving prop defaults to
 * false, so a dropped or misspelled binding would hide the Knowledge tab, the text extraction and
 * the Schwarm tab on servers with AI on — while the computed-property tests, which set `aiEnabled`
 * on hand-built instances, stay green. These tests mount the real parents and read the prop the
 * stubbed child actually receives.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const { api, recordingStub } = vi.hoisted(() => {
	const api = { aiEnabled: true }
	// A child stand-in that renders the aiEnabled prop it received (render function: the test
	// build has no runtime template compiler).
	const recordingStub = (name) => ({
		default: {
			name,
			props: { aiEnabled: { type: Boolean, default: false } },
			inheritAttrs: false,
			render() {
				// eslint-disable-next-line no-undef
				return globalThis.__h('div', { class: 'stub-' + name, 'data-ai-enabled': String(this.aiEnabled) })
			},
		},
	})
	return { api, recordingStub }
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/settings/tools') {
				return Promise.resolve({ data: { enabled_tools: null, ai_enabled: api.aiEnabled } })
			}
			if (url === '/apps/learning/api/courses/5') {
				return Promise.resolve({
					data: {
						id: 5,
						title: 'Kurs',
						instructor_id: 'teacher',
						is_instructor: true,
						material_folder: '/Kurs/Material',
						mode_config: { training: true },
						pools: [],
						members: [],
					},
				})
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), url)),
}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'teacher', isAdmin: false }) }))

vi.mock('@nextcloud/vue/components/NcButton', () => recordingStub('NcButton'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => recordingStub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => recordingStub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => recordingStub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => recordingStub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => recordingStub('NcNoteCard'))

// CourseDetail's children (the tab under test is swapped per describe block below)
vi.mock('../../src/components/CourseTabTeilnehmer.vue', () => recordingStub('CourseTabTeilnehmer'))
vi.mock('../../src/components/CourseTabWettbewerb.vue', () => recordingStub('CourseTabWettbewerb'))
vi.mock('../../src/components/CourseTabKommunikation.vue', () => recordingStub('CourseTabKommunikation'))
vi.mock('../../src/components/CourseTabVerwaltung.vue', () => recordingStub('CourseTabVerwaltung'))
vi.mock('../../src/components/ExamReadiness.vue', () => recordingStub('ExamReadiness'))

// CourseTabLernraum's children
vi.mock('../../src/components/CourseMaterials.vue', () => recordingStub('CourseMaterials'))
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => recordingStub('CourseKnowledgeImport'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => recordingStub('KnowledgeModeration'))
vi.mock('../../src/components/AuthFlowSimulator.vue', () => recordingStub('AuthFlowSimulator'))
vi.mock('../../src/components/DnsResolver.vue', () => recordingStub('DnsResolver'))
vi.mock('../../src/components/ExamMode.vue', () => recordingStub('ExamMode'))
vi.mock('../../src/components/FirewallBuilder.vue', () => recordingStub('FirewallBuilder'))
vi.mock('../../src/components/LeitnerMode.vue', () => recordingStub('LeitnerMode'))
vi.mock('../../src/components/NatTable.vue', () => recordingStub('NatTable'))
vi.mock('../../src/components/PortScanner.vue', () => recordingStub('PortScanner'))
vi.mock('../../src/components/RoutingTable.vue', () => recordingStub('RoutingTable'))
vi.mock('../../src/components/SubnetCalculator.vue', () => recordingStub('SubnetCalculator'))
vi.mock('../../src/components/TrainingMode.vue', () => recordingStub('TrainingMode'))
vi.mock('../../src/components/WiresharkLite.vue', () => recordingStub('WiresharkLite'))

import CourseTabLernraum from '../../src/components/CourseTabLernraum.vue'

globalThis.__h = h
globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)
globalThis.n = (_app, singular, plural, count) => (count === 1 ? singular : plural)

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

function receivedAi(host, name) {
	const el = host.querySelector('.stub-' + name)
	return el ? el.getAttribute('data-ai-enabled') : null
}

let host, app

function mountInto(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	return app.mount(host)
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

describe('CourseDetail hands the AI switch to its tabs (GitHub #35)', () => {
	let CourseDetail

	beforeEach(async () => {
		vi.clearAllMocks()
		vi.doMock('../../src/components/CourseTabLernraum.vue', () => recordingStub('CourseTabLernraum'))
		vi.resetModules()
		CourseDetail = (await import('../../src/components/CourseDetail.vue')).default
	})

	afterEach(() => {
		vi.doUnmock('../../src/components/CourseTabLernraum.vue')
	})

	for (const on of [true, false]) {
		it(`Lernraum and Kommunikation receive ai_enabled=${on} from /api/settings/tools`, async () => {
			api.aiEnabled = on
			const root = mountInto(CourseDetail, { courseId: 5, userRole: 'instructor' })
			await flush()

			expect(receivedAi(host, 'CourseTabLernraum')).toBe(String(on))

			const detail = root.$.subTree.component.proxy
			detail.activeMegaTab = 'kommunikation'
			await flush()
			expect(receivedAi(host, 'CourseTabKommunikation')).toBe(String(on))
		})
	}
})

describe('CourseTabLernraum hands the AI switch to the materials page (GitHub #35)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	for (const on of [true, false]) {
		it(`CourseMaterials receives aiEnabled=${on}`, async () => {
			const root = mountInto(CourseTabLernraum, {
				courseId: 5,
				course: { id: 5, is_instructor: true, material_folder: '/Kurs/Material', mode_config: { training: true } },
				userRole: 'instructor',
				actsAsInstructor: true,
				coursePools: [],
				allPools: [],
				activeTab: 'materials',
				contentLanguage: 'de',
				aiEnabled: on,
			})
			await flush()
			const lernraum = root.$.subTree.component.proxy
			if (lernraum.currentSubTab !== 'materials') {
				lernraum.currentSubTab = 'materials'
				await flush()
			}

			expect(receivedAi(host, 'CourseMaterials')).toBe(String(on))
		})
	}
})
