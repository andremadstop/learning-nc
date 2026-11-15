/**
 * GitHub #46: "when gamification is disabled on a server, almost nothing on that tab matters".
 * The pool window's Statistics tab (AnalyticsDashboard) showed the XP level, the streak, daily
 * missions and achievements regardless of the admin's gamification switch. Off, they are gone;
 * the box distribution, mastery and accuracy stay. On, the tab looks as before.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({
	api: {
		userState: {
			streak: { current_streak: 4, longest_streak: 9, freeze_tokens: 1 },
			badges: [{ badge_id: 'pioneer', name: 'Pioneer', emoji: '*', earned: true, earned_at: 1700000000 }],
			xp: { total_xp: 340, level: 3, xp_in_level: 40, xp_to_next_level: 100, level_progress_pct: 40 },
			progress: [],
			missions: { date: '2026-11-15', missions: [{ key: 'm1', name: 'Mission', description: 'Do it', current: 1, target: 3, xp: 20 }] },
		},
	},
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/leitner/stats')) {
				return Promise.resolve({ data: { total: 10, mastered: 2, box_1: 8, box_5: 2, accuracy: 70, mastery_percentage: 20 } })
			}
			if (url.endsWith('/api/v1/user/state')) {
				return Promise.resolve({ data: api.userState })
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button type="button"><slot /></button>' } }))

import axios from '@nextcloud/axios'
import AnalyticsDashboard from '../../src/components/AnalyticsDashboard.vue'

let host, app

async function mount(props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(AnalyticsDashboard, { poolId: 7, ...props })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.mount(host)
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

beforeEach(() => {
	vi.spyOn(console, 'error').mockImplementation(() => {})
	axios.get.mockClear()
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('AnalyticsDashboard and the gamification switch (GitHub #46)', () => {
	it('with gamification off: no XP level, streak, missions or achievements; the learning data stays', async () => {
		await mount({ gamificationEnabled: false })
		expect(host.querySelector('.xp-hero')).toBeNull()
		expect(host.querySelector('.streak-card')).toBeNull()
		expect(host.querySelector('.missions-section')).toBeNull()
		expect(host.querySelector('.achievements-section')).toBeNull()
		expect(host.textContent).not.toContain('XP')
		expect(host.querySelector('.card-mastered')).not.toBeNull()
		expect(host.querySelector('.leitner-tower-desktop')).not.toBeNull()
		expect(host.querySelector('.mastery-ring')).not.toBeNull()
		// Nothing gamified to show, so the user state is not requested at all.
		expect(axios.get.mock.calls.some(([url]) => url.endsWith('/api/v1/user/state'))).toBe(false)
	})

	it('with gamification on (and by default) the tab looks as before', async () => {
		await mount({})
		expect(host.querySelector('.xp-hero')).not.toBeNull()
		expect(host.querySelector('.streak-card')).not.toBeNull()
		expect(host.querySelector('.missions-section')).not.toBeNull()
		expect(host.querySelector('.achievements-section')).not.toBeNull()
	})
})
