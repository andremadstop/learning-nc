/**
 * GitHub #84 / #87 (review), the instructor's member list:
 *   - the confirm of "open a new certification cycle" said "Frühere Prüfungen zählen dann nicht
 *     mehr" for every reason. For used-up attempts only the attempt count starts again (without a
 *     certificate or in an open renewal window an earlier pass still counts), so that sentence is
 *     only said after a revoke or an expiry;
 *   - the uid went through the escaping translate(): "o'brien" read "o&#39;brien" in the confirm
 *     and the aria-label;
 *   - the row showed only the uid, while the instructors' notification names the learner by
 *     display name — on LDAP/SSO instances the uid is a GUID, and the two could not be matched.
 *
 * The real translate() of @nextcloud/l10n, not a mock: a mock that never escapes cannot see this.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'alice' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))
vi.mock('../../src/components/StudentDetail.vue', () => stub('StudentDetail'))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))
vi.mock('../../src/components/Klassenbuch.vue', () => stub('Klassenbuch'))
vi.mock('../../src/components/CourseMemberPicker.vue', () => stub('CourseMemberPicker'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))

let host, app

beforeEach(() => {
	vi.clearAllMocks()
	globalThis.t = translate
	globalThis.n = translatePlural
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	delete window.confirm
})

async function mountMembers(members, cycleRows) {
	axios.get.mockImplementation((url) => Promise.resolve(
		url.includes('/certification-cycles') ? { data: { members: cycleRows } } : { data: [] },
	))
	const { default: CourseTabTeilnehmer } = await import('../../src/components/CourseTabTeilnehmer.vue')
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(CourseTabTeilnehmer, {
			courseId: 5,
			course: { is_instructor: true, cert_enabled: true, mode_config: { course_summary: false } },
			userRole: 'instructor',
			actsAsInstructor: true,
			activeTab: 'members',
			courseMembers: members,
		}),
	})
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural
	app.config.warnHandler = () => {}
	app.mount(host)
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function confirmTextFor(reason) {
	await mountMembers(
		[{ id: 31, user_id: "o'brien", display_name: "Seán O'Brien", role: 'student', enrolled_at: 1 }],
		[{ member_id: 31, reason, new_cycle: null, exhausted_pool_ids: [] }],
	)
	window.confirm = vi.fn(() => false)
	host.querySelector('[data-test="member-new-cycle"]').click()
	await nextTick()
	expect(window.confirm).toHaveBeenCalledTimes(1)
	const text = window.confirm.mock.calls[0][0]
	app.unmount()
	host.remove()
	app = null
	host = null
	return text
}

describe('new certification cycle: the confirm says what really happens', () => {
	it('used-up attempts: only the attempts start again, nothing about earlier exams', async () => {
		const text = await confirmTextFor('attempts')
		expect(text).toContain('Die Prüfungsversuche beginnen neu.')
		expect(text).not.toContain('Frühere Prüfungen zählen dann nicht mehr')
		expect(text).not.toContain('Pflichtdokumente')
	})

	it('revoked: earlier exams stop counting and the documents are confirmed again', async () => {
		const text = await confirmTextFor('revoked')
		expect(text).toContain('Frühere Prüfungen zählen dann nicht mehr')
		expect(text).toContain('die Pflichtdokumente müssen erneut bestätigt werden')
	})

	it('expired: earlier exams stop counting, no document confirmation', async () => {
		const text = await confirmTextFor('expired')
		expect(text).toContain('Frühere Prüfungen zählen dann nicht mehr')
		expect(text).not.toContain('Pflichtdokumente')
	})

	it('names the learner as written, by display name', async () => {
		const text = await confirmTextFor('attempts')
		expect(text).toContain("für Seán O'Brien öffnen?")
		expect(text).not.toContain('&#39;')
	})
})

describe('member row', () => {
	it('shows the display name, the uid as secondary text, and an unescaped aria-label', async () => {
		await mountMembers(
			[
				{ id: 31, user_id: "o'brien", display_name: "Seán O'Brien", role: 'student', enrolled_at: 1 },
				{ id: 32, user_id: 'ben', display_name: 'ben', role: 'student', enrolled_at: 1 },
			],
			[{ member_id: 31, reason: 'attempts', new_cycle: null, exhausted_pool_ids: [4] }],
		)
		const names = [...host.querySelectorAll('[data-test="member-name"]')].map((n) => n.textContent.trim())
		expect(names).toEqual(["Seán O'Brien", 'ben'])
		const uids = [...host.querySelectorAll('[data-test="member-uid"]')].map((n) => n.textContent.trim())
		expect(uids).toEqual(["o'brien"])
		expect(host.querySelector('[data-test="member-new-cycle"]').getAttribute('aria-label'))
			.toBe("Neuen Zertifizierungszyklus für Seán O'Brien öffnen")
	})

	// Pre-existing, the same class: the remove dialog escaped the uid and did not use the name.
	it('the remove dialog names the member as written, by display name', async () => {
		await mountMembers([{ id: 31, user_id: "o'brien", display_name: "Seán O'Brien", role: 'student', enrolled_at: 1 }], [])
		host.querySelector('button[aria-label="Remove member"]').click()
		await nextTick()
		expect(host.textContent).toContain('"Seán O\'Brien" aus diesem Kurs entfernen?')
		expect(host.textContent).not.toContain('&#39;')
	})

	it('falls back to the uid without a display name', async () => {
		await mountMembers([{ id: 33, user_id: 'carl', role: 'student', enrolled_at: 1 }], [])
		expect(host.querySelector('[data-test="member-name"]').textContent.trim()).toBe('carl')
		expect(host.querySelector('[data-test="member-uid"]')).toBeNull()
	})
})
