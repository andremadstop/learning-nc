/**
 * Audit 2026-11 (S32): "Überspringen" on the Jumpstart screen only emitted 'next'. A pool tapped
 * before stayed in store.selectedStarterPoolId (persisted), and OnboardingRedesign.finalize()
 * imported it anyway. The UI has no way to deselect a pool, so Skip is the only way to decline.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: [{ id: 3, title: 'Linux', question_count: 10 }, { id: 4, title: 'Netz', question_count: 5 }] })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ t: (app, text) => text, translate: (app, text) => text }))

import OnbJumpstart from '../../src/components/onboarding/OnbJumpstart.vue'
import { useOnboardingStore } from '../../src/stores/onboardingStore.js'

let host, app, nextCount

async function settle() {
	for (let i = 0; i < 4; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

beforeEach(async () => {
	localStorage.clear()
	const pinia = createPinia()
	setActivePinia(pinia)
	nextCount = 0
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(OnbJumpstart, { onNext: () => { nextCount++ } }) })
	app.use(pinia)
	app.config.warnHandler = () => {}
	app.mount(host)
	await settle()
})

afterEach(() => {
	app?.unmount()
	host?.remove()
})

const buttonByText = (text) => [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === text)

describe('OnbJumpstart: skipping declines the starter pool', () => {
	it('a selected pool is cleared when the user skips', async () => {
		host.querySelector('.onb-pool-card').click()
		await settle()
		expect(useOnboardingStore().selectedStarterPoolId).toBe(3)

		buttonByText('Ueberspringen').click()
		await settle()
		expect(useOnboardingStore().selectedStarterPoolId).toBeNull()
		expect(nextCount).toBe(1)
	})

	it('"Weiter" keeps the selected pool', async () => {
		host.querySelectorAll('.onb-pool-card')[1].click()
		await settle()
		buttonByText('Weiter').click()
		await settle()
		expect(useOnboardingStore().selectedStarterPoolId).toBe(4)
		expect(nextCount).toBe(1)
	})
})
