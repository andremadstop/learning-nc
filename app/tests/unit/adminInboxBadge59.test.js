import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({ uid: 'alice' })),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/ExamMode.vue', () => stub('ExamMode'))
vi.mock('../../src/components/LeagueTab.vue', () => stub('LeagueTab'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/StudentDetail.vue', () => stub('StudentDetail'))
vi.mock('../../src/components/DuelMode.vue', () => stub('DuelMode'))
vi.mock('../../src/components/GameshowMode.vue', () => stub('GameshowMode'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))
vi.mock('../../src/components/ArenaSelector.vue', () => stub('ArenaSelector'))
vi.mock('../../src/components/AbenteuerMode.vue', () => stub('AbenteuerMode'))
vi.mock('../../src/components/CourseTabLernraum.vue', () => stub('CourseTabLernraum'))
vi.mock('../../src/components/CourseTabTeilnehmer.vue', () => stub('CourseTabTeilnehmer'))
vi.mock('../../src/components/CourseTabWettbewerb.vue', () => stub('CourseTabWettbewerb'))
vi.mock('../../src/components/CourseTabKommunikation.vue', () => stub('CourseTabKommunikation'))
vi.mock('../../src/components/CourseTabVerwaltung.vue', () => stub('CourseTabVerwaltung'))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/StudentKnowledgeContribute.vue', () => stub('StudentKnowledgeContribute'))
vi.mock('../../src/components/CourseFeed.vue', () => stub('CourseFeed'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<div />' } }))

import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import AdminSettings from '../../src/components/AdminSettings.vue'
import CourseDetail from '../../src/components/CourseDetail.vue'

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

// GitHub #59 (rest): the admin inbox sat at the very bottom of the settings with raw status
// values, and nothing at the course tab said that learner questions were waiting.

describe('the admin inbox and the tab badge (GitHub #59)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
			})

	it('the admin inbox comes first, names its statuses and points to the courses', () => {
		const source = readFileSync(resolve(__dirname, '../../src/components/AdminSettings.vue'), 'utf8')
		const template = source.slice(0, source.indexOf('<script>'))
		// above the settings, not below the audit trail
		expect(template.indexOf('class="ticket-section"')).toBeGreaterThan(0)
		expect(template.indexOf('class="ticket-section"')).toBeLessThan(template.indexOf('class="switch-row"'))
		expect(template.indexOf('class="ticket-section"')).toBeLessThan(template.indexOf('audit-liveness'))
		expect(template).toContain('{{ ticketStatusLabel(ticket.status) }}')
		const vm = { supportTickets: [{ status: 'open' }, { status: 'answered' }, { status: 'open' }] }
		expect(AdminSettings.computed.openSupportTicketCount.call(vm)).toBe(2)
		expect(AdminSettings.computed.coursesUrl.call(vm)).toBe('/apps/learning/courses')
		expect(AdminSettings.methods.ticketStatusLabel('open')).toBe('Offen (Status einer Frage)')
		expect(AdminSettings.methods.ticketStatusLabel('answered')).not.toBe('answered')
	})

	it('CourseDetail asks for the count only for an instructor outside the student view', async () => {
		axios.get.mockResolvedValue({ data: { open_count: 4 } })
		const learner = { isInstructor: false, courseId: 7, openQuestionsCount: 0 }
		await CourseDetail.methods.fetchOpenQuestionsCount.call(learner)
		expect(axios.get).not.toHaveBeenCalled()

		const instructor = { isInstructor: true, courseId: 7, openQuestionsCount: 0 }
		await CourseDetail.methods.fetchOpenQuestionsCount.call(instructor)
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/7/support-tickets/open-count')
		expect(instructor.openQuestionsCount).toBe(4)
	})
})
