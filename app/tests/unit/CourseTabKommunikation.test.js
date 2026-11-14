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
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/CourseFeed.vue', () => stub('CourseFeed'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))
vi.mock('../../src/components/StudentKnowledgeContribute.vue', () => stub('StudentKnowledgeContribute'))

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import CourseTabKommunikation from '../../src/components/CourseTabKommunikation.vue'

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/CourseTabKommunikation.vue'), 'utf8')
const TEMPLATE = SOURCE.slice(0, SOURCE.indexOf('<script>'))
const STYLE = SOURCE.slice(SOURCE.indexOf('<style'))

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseTabKommunikation.data === 'function' ? CourseTabKommunikation.data() : {}
	const instance = {
		...data,
		courseId: 5,
		course: {
			is_instructor: false,
		},
		userRole: 'student',
		activeTab: 'feed',
		$emit: vi.fn(),
		// The prop CourseDetail hands down. Set per test; the harness does not re-derive it from
		// the course (that copy of CourseDetail's rule would test itself, not the component).
		actsAsInstructor: false,
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabKommunikation.computed.isInstructor.call(instance) },
		visibleSubTabs: { get: () => CourseTabKommunikation.computed.visibleSubTabs.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseTabKommunikation.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseTabKommunikation', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('builds instructor sub-tabs: announcements, feed, requests', () => {
		const instance = createInstance({
			course: { is_instructor: true },
			userRole: 'instructor',
			actsAsInstructor: true,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['announcements', 'feed', 'requests'])
	})

	// GitHub #35: the student view must win over the course membership in every mega-tab.
	it('the student view (actsAsInstructor false) shows an own course with the learner sub-tabs', () => {
		const instance = createInstance({ course: { is_instructor: true }, userRole: 'student', actsAsInstructor: false, aiEnabled: true })

		expect(instance.isInstructor).toBe(false)
		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['feed', 'buddies', 'schwarm'])
	})

	it('builds student sub-tabs: feed, buddies, schwarm', () => {
		const instance = createInstance({ aiEnabled: true })

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['feed', 'buddies', 'schwarm'])
	})

	// GitHub #35: with AI off the knowledge moderation is gone, so nobody could ever read a swarm
	// contribution; the swarm is left out instead of collecting into a queue nobody opens.
	it('leaves out the swarm while the admin has AI switched off', () => {
		const instance = createInstance({ aiEnabled: false })

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['feed', 'buddies'])
	})

	it('emits tab-change with correct leaf ID when selecting a sub-tab', () => {
		const instance = createInstance({
			currentSubTab: 'feed',
		})

		instance.selectSubTab('buddies')

		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'buddies')
		expect(instance.currentSubTab).toBe('buddies')
	})

	it('syncs from activeTab and falls back to default when tab is invalid', () => {
		const instance = createInstance({
			currentSubTab: 'feed',
		})

		instance.syncFromActiveTab('unknown-tab')

		expect(instance.currentSubTab).toBe('feed')
	})

	it('component has correct name', () => {
		expect(CourseTabKommunikation.name).toBe('CourseTabKommunikation')
	})

	// GitHub #40 (a): Nextcloud core sets every textarea to width: 130px.
	it('gives the announcement and the answer textarea their own full width', () => {
		expect(TEMPLATE).toMatch(/class="nc-textarea announcement-textarea" rows="6"/)
		expect(TEMPLATE).toMatch(/class="nc-textarea ticket-reply-textarea"/)
		const rule = STYLE.match(/\.announcement-textarea,\s*\.ticket-reply-textarea\s*\{[^}]*\}/)?.[0] || ''
		expect(rule).toMatch(/width:\s*100%/)
	})

	// GitHub #40 (b): the list under "New announcement" duplicated the feed; delete lives in the feed.
	it('shows no second announcement list and lets the feed manage announcements for instructors', () => {
		expect(TEMPLATE).not.toMatch(/announcements-list/)
		expect(TEMPLATE).toMatch(/<CourseFeed :course-id="courseId" :can-manage="isInstructor" \/>/)
	})

	it('switches to the feed after publishing', async () => {
		axios.post.mockResolvedValue({ data: { announcement: { id: 1 } } })
		const instance = createInstance({ actsAsInstructor: true, currentSubTab: 'announcements', newAnnouncementTitle: 'Hi', newAnnouncementBody: 'a\nb' })

		await instance.createAnnouncement()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/5/announcements', { title: 'Hi', body: 'a\nb' })
		expect(instance.currentSubTab).toBe('feed')
		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'feed')
		expect(instance.newAnnouncementTitle).toBe('')
		expect(showError).not.toHaveBeenCalled()
	})

	// GitHub #40 (f): publish and send answer failed with nothing but a console line.
	it('shows a failed publish and keeps the typed announcement', async () => {
		axios.post.mockRejectedValue({ response: { status: 429 } })
		const instance = createInstance({ actsAsInstructor: true, currentSubTab: 'announcements', newAnnouncementTitle: 'Hi', newAnnouncementBody: 'Text' })

		await instance.createAnnouncement()

		expect(showError).toHaveBeenCalledTimes(1)
		expect(instance.newAnnouncementTitle).toBe('Hi')
		expect(instance.newAnnouncementBody).toBe('Text')
		expect(instance.currentSubTab).toBe('announcements')
		expect(instance.savingAnnouncement).toBe(false)
	})

	it('shows a failed answer and keeps the typed reply and the open ticket', async () => {
		axios.post.mockRejectedValue({ response: { status: 403 } })
		const ticket = { id: 7, status: 'open', subject: 'S', message: 'M' }
		const instance = createInstance({ actsAsInstructor: true, courseTickets: [ticket], ticketReplies: { 7: 'Meine Antwort' } })

		await instance.answerTicket(7)

		expect(showError).toHaveBeenCalledTimes(1)
		expect(ticket.status).toBe('open')
		expect(instance.ticketReplies[7]).toBe('Meine Antwort')
		expect(instance.answeringTickets[7]).toBeUndefined()
	})

	it('a sent answer marks the ticket answered', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const ticket = { id: 7, status: 'open', subject: 'S', message: 'M' }
		const instance = createInstance({ actsAsInstructor: true, courseTickets: [ticket], ticketReplies: { 7: 'Antwort' } })

		await instance.answerTicket(7)

		expect(ticket.status).toBe('answered')
		expect(ticket.answer_text).toBe('Antwort')
		expect(showError).not.toHaveBeenCalled()
	})

	// GitHub #40 (d): "Requests" read like a swarm feature; the inbox is named for what it holds,
	// its empty state says where questions come from, and the badge is translated.
	it('names the instructor inbox for the learners\' questions and explains its empty state', () => {
		const instance = createInstance({ actsAsInstructor: true })
		const requests = instance.visibleSubTabs.find((tab) => tab.id === 'requests')

		expect(requests.label).toBe('Fragen von Lernenden')
		expect(TEMPLATE).toMatch(/Hilfe-Assistenten VirtuProf/)
		expect(TEMPLATE).not.toMatch(/No open requests for this course/)
		expect(instance.ticketStatusLabel('open')).toBe('Open')
		expect(instance.ticketStatusLabel('answered')).toBe('Answered')
		expect(TEMPLATE).toMatch(/\{\{ ticketStatusLabel\(ticket\.status\) \}\}/)
	})
})

