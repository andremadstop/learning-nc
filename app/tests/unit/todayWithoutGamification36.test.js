/**
 * GitHub #36: the start page "Heute" stays when the admin switches gamification off, but loses
 * what only gamification fills — the daily challenge, the streak and the level. The learner
 * onboarding slide "progress" gets a text without streak and level, and the Skill Map stops
 * showing Leitner boxes for pools of a course with Leitner off. With gamification on nothing
 * changes.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => Promise.resolve({
			data: url.includes('user/state')
				? { streak: { current_streak: 4, longest_streak: 9 }, xp: { level: 7 }, daily_progress: { cards_reviewed_today: 3, sessions_today: 1 } }
				: {},
		})),
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

import StudentDashboard from '../../src/components/StudentDashboard.vue'
import OnbTour from '../../src/components/onboarding/OnbTour.vue'
import OnboardingIntro from '../../src/components/OnboardingIntro.vue'
import SkillMap from '../../src/components/SkillMap.vue'
import { STUDENT_SLIDES, STUDENT_SLIDES_FULL, slidesForGamification } from '../../src/utils/onboarding-slides.js'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

let host, app

async function render(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
	app.config.warnHandler = () => {}
	const pinia = createPinia()
	app.use(pinia)
	setActivePinia(pinia)
	app.mount(host)
	await flush()
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

function progressLabels() {
	return Array.from(host.querySelectorAll('.progress-label')).map((el) => el.textContent.trim())
}

describe('StudentDashboard "Heute" without gamification (GitHub #36)', () => {
	it('drops the daily challenge, the streak and the level, keeps due cards, progress, schedule and feed', async () => {
		await render(StudentDashboard, { gamificationEnabled: false })
		expect(host.querySelector('.stub-DailyChallengeCard')).toBeNull()
		expect(host.querySelector('.widget-streak')).toBeNull()
		expect(progressLabels()).not.toContain('Level')
		expect(host.querySelector('.progress-stats--two'), 'two tiles fill the row').not.toBeNull()
		expect(host.querySelector('.widget-smartqueue')).not.toBeNull()
		expect(progressLabels()).toContain('Sessions')
		expect(host.querySelector('.stub-GlobalFeed')).not.toBeNull()
		expect(host.querySelector('.stub-CheatSheetExport')).not.toBeNull()
	})

	it('is unchanged with gamification on', async () => {
		await render(StudentDashboard, {})
		expect(host.querySelector('.stub-DailyChallengeCard')).not.toBeNull()
		expect(host.querySelector('.widget-streak .streak-number')?.textContent).toBe('4')
		expect(progressLabels()).toContain('Level')
		expect(host.querySelector('.progress-stats--two')).toBeNull()
	})
})

describe('learner onboarding slide "progress" without gamification (GitHub #36)', () => {
	const progressText = (slides) => slides.find((slide) => slide.id === 'progress').textKey

	it('swaps in the texts without streak, XP and level in both learner decks', () => {
		expect(progressText(slidesForGamification(STUDENT_SLIDES, false))).not.toMatch(/Streak|Level/)
		expect(progressText(slidesForGamification(STUDENT_SLIDES_FULL, false))).toBe('slide:student:progress:text:without-gamification')
		expect(slidesForGamification(STUDENT_SLIDES, true)).toBe(STUDENT_SLIDES)
		expect(STUDENT_SLIDES.find((s) => s.id === 'progress').textKey).toMatch(/Streak und Level/)
	})

	it('the wizard tour shows the variant on its progress slide', async () => {
		const index = STUDENT_SLIDES.findIndex((slide) => slide.id === 'progress')
		for (const [enabled, pattern] of [[false, /Tagesfortschritt/], [true, /Streak und Level/]]) {
			await render(OnbTour, { gamificationEnabled: enabled })
			const store = (await import('../../src/stores/onboardingStore.js')).useOnboardingStore()
			store.setRole('student')
			store.setTourSlide(index)
			await flush()
			expect(host.querySelector('.onb-tour__text').textContent).toMatch(pattern)
			app.unmount()
			host.remove()
			app = null
		}
	})

	it('the VirtuProf intro uses the variant too', () => {
		const slides = OnboardingIntro.computed.slides.call({ role: 'student', gamificationEnabled: false })
		expect(progressText(slides)).toBe('slide:student:progress:text:without-gamification')
		const on = OnboardingIntro.computed.slides.call({ role: 'student', gamificationEnabled: true })
		expect(progressText(on)).toBe('slide:student:progress:text')
	})
})

describe('Skill Map Leitner rows (GitHub #36)', () => {
	const leitnerFor = (pool) => SkillMap.computed.selectedNodeLeitner.call({
		selectedNode: { id: 3 },
		pools: [{ pool_id: 3, leitner_stats: { total: 10, box_1: 4, box_5: 6 }, ...pool }],
	})

	it('shows no Leitner boxes for a pool whose course has Leitner off', () => {
		expect(leitnerFor({ leitner_enabled: false })).toBeNull()
	})

	it('keeps them with Leitner on or without the flag (older server)', () => {
		expect(leitnerFor({ leitner_enabled: true })).toEqual({ box_1: 4, box_5: 6 })
		expect(leitnerFor({})).toEqual({ box_1: 4, box_5: 6 })
	})
})
