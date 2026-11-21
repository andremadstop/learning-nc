/**
 * GitHub #101: since 5.17.0 the activity "Achievement unlocked" opens Today, which showed streak
 * and level but not one achievement. Today now has an achievements section: the unlocked ones,
 * most recent first, six at most until "show all", a friendly empty state, and nothing at all with
 * gamification off. The activity link names the section (?section=achievements) and Today scrolls
 * there and puts the focus on its heading.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, reactive } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})

const state = vi.hoisted(() => ({ badges: [], failing: [] }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (state.failing.some((part) => url.includes(part))) {
				return Promise.reject(new Error('Request failed with status code 500'))
			}
			return Promise.resolve({
				data: url.includes('user/state')
					? { streak: { current_streak: 2 }, xp: { level: 3 }, daily_progress: {}, badges: state.badges }
					: {},
			})
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app, text) => text, translate: (_app, text) => text }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: `<div class="stub-${name}" />` } }),
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('../../src/components/DailyChallengeCard.vue', () => stub('DailyChallengeCard'))
vi.mock('../../src/components/GlobalFeed.vue', () => stub('GlobalFeed'))
vi.mock('../../src/components/CourseTimeline.vue', () => stub('CourseTimeline'))
vi.mock('../../src/components/CheatSheetExport.vue', () => stub('CheatSheetExport'))

import axios from '@nextcloud/axios'
import StudentDashboard, { ACHIEVEMENTS_SECTION } from '../../src/components/StudentDashboard.vue'
import { RECENT_BADGE_LIMIT, recentEarnedBadges } from '../../src/utils/recentBadges.js'

const badge = (id, earnedAt, extra = {}) => ({
	badge_id: id,
	name: `Badge ${id}`,
	emoji: '*',
	earned: earnedAt !== false,
	earned_at: earnedAt === false ? null : earnedAt,
	legacy: false,
	...extra,
})

async function flush() {
	for (let i = 0; i < 8; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

let host, app, dashboard

async function render(props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	const reactiveProps = reactive({ ...props })
	dashboard = null
	app = createApp({ render: () => h(StudentDashboard, { ...reactiveProps, ref: (vm) => { dashboard = vm } }) })
	app.config.globalProperties.t = globalThis.t
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return reactiveProps
}

beforeEach(() => {
	state.badges = []
	state.failing = []
	axios.get.mockClear()
	document.activeElement?.blur?.()
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

/** Every GET Today makes on mount; the achievements add none of their own. */
const LOAD_URLS = [
	'/apps/learning/api/courses',
	'/apps/learning/api/leitner/queue/count',
	'/apps/learning/api/leitner/remediation/count',
	'/apps/learning/api/maintenance/stats',
	'/apps/learning/api/v1/user/state',
]
const requestedUrls = () => axios.get.mock.calls.map(([url]) => url).sort()

const names = () => Array.from(host.querySelectorAll('.achievement-name')).map((el) => el.textContent.trim())

describe('recentEarnedBadges (GitHub #101)', () => {
	it('keeps only unlocked badges, most recent first, ties by id, undated last', () => {
		const sorted = recentEarnedBadges([
			badge('a', 100),
			badge('locked', false),
			badge('c', '300'), // PostgreSQL may send the number as a string
			badge('b', 300),
			badge('undated', null, { earned: true }),
			badge('old', 50, { legacy: true }),
		], null)
		expect(sorted.map((b) => b.badge_id)).toEqual(['b', 'c', 'a', 'old', 'undated'])
	})

	it('stops at the limit and survives a missing list', () => {
		const many = Array.from({ length: 9 }, (_, i) => badge(`b${i}`, 1000 + i))
		expect(RECENT_BADGE_LIMIT).toBe(6)
		expect(recentEarnedBadges(many).map((b) => b.badge_id)).toEqual(['b8', 'b7', 'b6', 'b5', 'b4', 'b3'])
		expect(recentEarnedBadges(undefined)).toEqual([])
		expect(recentEarnedBadges(many, 0)).toEqual([])
	})
})

describe('Today shows the achievements (GitHub #101)', () => {
	it('lists the unlocked ones most recent first as a labelled list, from the user state it already loads', async () => {
		state.badges = [badge('first', 1700000000), badge('locked', false), badge('newest', 1800000000)]
		await render({})
		const section = host.querySelector('section.widget-achievements')
		expect(section).not.toBeNull()
		const heading = section.querySelector('#dashboard-achievements-heading')
		expect(section.getAttribute('aria-labelledby')).toBe('dashboard-achievements-heading')
		expect(heading.tagName).toBe('H4')
		expect(heading.textContent.trim()).toBe('Achievements')
		expect(section.querySelectorAll('ul.achievements-list > li')).toHaveLength(2)
		expect(names()).toEqual(['Badge newest', 'Badge first'])
		expect(section.querySelector('.achievement-date').textContent.trim()).toBe(new Date(1800000000 * 1000).toLocaleDateString())
		expect(section.querySelector('.achievements-empty')).toBeNull()
		expect(section.querySelector('.achievements-error')).toBeNull()
		// No request of its own: exactly the loads Today made before, user state once.
		expect(requestedUrls()).toEqual(LOAD_URLS)
	})

	it('shows six until "show all", which expands and collapses again', async () => {
		state.badges = Array.from({ length: 8 }, (_, i) => badge(`b${i}`, 1000 + i))
		await render({})
		expect(names()).toHaveLength(6)
		expect(names()[0]).toBe('Badge b7')
		const toggle = host.querySelector('.achievements-toggle')
		expect(toggle.getAttribute('aria-expanded')).toBe('false')
		expect(toggle.textContent.trim()).toBe('Alle Erfolge anzeigen (8)')
		toggle.click()
		await nextTick()
		expect(names()).toHaveLength(8)
		expect(toggle.getAttribute('aria-expanded')).toBe('true')
		expect(toggle.textContent.trim()).toBe('Weniger anzeigen')
		toggle.click()
		await nextTick()
		expect(names()).toHaveLength(6)
	})

	it('offers no toggle at six or fewer and has a friendly empty state', async () => {
		state.badges = [badge('locked', false)]
		await render({})
		expect(host.querySelector('.achievements-toggle')).toBeNull()
		expect(host.querySelector('.achievements-list')).toBeNull()
		expect(host.querySelector('.achievements-error')).toBeNull()
		expect(host.querySelector('.achievements-empty').textContent.trim())
			.toBe('Noch keine Erfolge freigeschaltet. Lern einfach weiter, der erste kommt bald.')
	})

	it('shows nothing, keeps no badges and adds no request with gamification off', async () => {
		state.badges = [badge('newest', 1800000000)]
		await render({ gamificationEnabled: false, focusSection: ACHIEVEMENTS_SECTION })
		// The other widgets are there, so an empty page cannot pass this test.
		expect(host.querySelector('.widget-progress')).not.toBeNull()
		expect(host.querySelector('.widget-achievements')).toBeNull()
		expect(host.querySelector('#dashboard-achievements-heading')).toBeNull()
		expect(host.textContent).not.toContain('Badge newest')
		// The user state is still loaded (daily progress needs it), but its badges are dropped.
		expect(requestedUrls()).toEqual(LOAD_URLS)
		expect(dashboard.badges).toEqual([])
		expect(document.activeElement).toBe(document.body)
	})
})

describe('a failed user state is not "no achievements" (GitHub #101)', () => {
	it('says the achievements could not be loaded instead of the friendly empty state', async () => {
		state.badges = [badge('newest', 1800000000)]
		state.failing = ['user/state']
		await render({})
		const section = host.querySelector('section.widget-achievements')
		expect(section).not.toBeNull()
		const error = section.querySelector('.achievements-error')
		expect(error).not.toBeNull()
		expect(error.getAttribute('role')).toBe('status')
		expect(error.textContent.trim())
			.toBe('Die Erfolge konnten nicht geladen werden. Lade die Seite neu, um es noch einmal zu versuchen.')
		expect(section.querySelector('.achievements-empty')).toBeNull()
		expect(section.querySelector('.achievements-list')).toBeNull()
		expect(section.querySelector('.achievements-toggle')).toBeNull()
	})

	it('leaves the other widgets as optional as before: they render with their defaults', async () => {
		state.failing = ['user/state']
		await render({})
		expect(host.querySelector('.loading-container')).toBeNull()
		expect(host.querySelector('.streak-number').textContent.trim()).toBe('0')
		expect(host.querySelector('.widget-progress')).not.toBeNull()
		expect(host.querySelector('.widget-smartqueue')).not.toBeNull()
	})

	it('another failing widget request does not turn the achievements into an error', async () => {
		state.failing = ['queue/count', 'remediation/count', 'courses', 'maintenance/stats']
		await render({})
		expect(host.querySelector('.achievements-error')).toBeNull()
		expect(host.querySelector('.achievements-empty')).not.toBeNull()
	})

	it('with gamification off a failed user state shows no achievements error either', async () => {
		state.failing = ['user/state']
		await render({ gamificationEnabled: false })
		expect(host.querySelector('.widget-achievements')).toBeNull()
		expect(host.querySelector('.achievements-error')).toBeNull()
	})
})

describe('the activity link lands on the section (GitHub #101)', () => {
	it('scrolls to the section and focuses its heading when the route names it', async () => {
		const scroll = vi.fn()
		const original = Element.prototype.scrollIntoView
		Element.prototype.scrollIntoView = scroll
		try {
			state.badges = [badge('newest', 1800000000)]
			await render({ focusSection: ACHIEVEMENTS_SECTION })
			const heading = host.querySelector('#dashboard-achievements-heading')
			expect(document.activeElement).toBe(heading)
			expect(heading.getAttribute('tabindex')).toBe('-1')
			expect(scroll).toHaveBeenCalledTimes(1)
			expect(scroll.mock.instances[0]).toBe(host.querySelector('section.widget-achievements'))
		} finally {
			Element.prototype.scrollIntoView = original
		}
	})

	it('leaves the focus alone without the section in the route, and follows a later in-app link', async () => {
		state.badges = [badge('newest', 1800000000)]
		const props = await render({})
		const heading = host.querySelector('#dashboard-achievements-heading')
		expect(document.activeElement).toBe(document.body)
		props.focusSection = ACHIEVEMENTS_SECTION
		await flush()
		expect(document.activeElement).toBe(heading)
	})

	it('App passes the route\'s ?section= to Today, and the activity names that section', () => {
		const appSource = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
		const tag = appSource.slice(appSource.indexOf('<StudentDashboard'), appSource.indexOf('/>', appSource.indexOf('<StudentDashboard')))
		expect(tag).toContain(':focus-section="String(route.query?.section || \'\')"')
		const provider = readFileSync(resolve(__dirname, '../../lib/Activity/Provider.php'), 'utf8')
		expect(provider).toContain(`'/dashboard?section=${ACHIEVEMENTS_SECTION}'`)
	})
})
