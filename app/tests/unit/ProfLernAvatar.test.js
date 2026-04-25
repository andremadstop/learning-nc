import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import ProfLernAvatar from '../../src/components/ProfLernAvatar.vue'

let mountedApp = null

beforeEach(() => {
	if (mountedApp) { mountedApp.unmount(); mountedApp = null }
	document.body.innerHTML = '<div id="app"></div>'
	setActivePinia(createPinia())
	window.matchMedia = vi.fn().mockImplementation(query => ({
		matches: false,
		media: query,
		onchange: null,
		addEventListener: vi.fn(),
		removeEventListener: vi.fn(),
		dispatchEvent: vi.fn(),
	}))
})

afterEach(() => {
	if (mountedApp) { mountedApp.unmount(); mountedApp = null }
	vi.useRealTimers()
})

function mount(props = {}) {
	const app = createApp({ render: () => h(ProfLernAvatar, props) })
	app.use(createPinia())
	app.config.globalProperties.t = (appName, str) => str
	app.mount('#app')
	mountedApp = app
	return document.getElementById('app')
}

describe('ProfLernAvatar (Phase 151 Plan 06)', () => {
	it('CLASSIC-01 renames VirtuProfAvatar.vue to ProfLernAvatar.vue (file exists at new path)', () => {
		expect(ProfLernAvatar).toBeDefined()
		expect(ProfLernAvatar.name || '').toMatch(/ProfLernAvatar/)
	})

	it('CLASSIC-02-q renders question mark on body (text element with "?")', () => {
		const root = mount()
		const text = root.querySelector('svg text')
		expect(text).not.toBeNull()
		expect(text.textContent.trim()).toBe('?')
	})

	it('CLASSIC-02-book renders a book SVG group (g[data-prof-feature="book"] OR g.book)', () => {
		const root = mount()
		const book = root.querySelector('svg g[data-prof-feature="book"], svg g.book')
		expect(book).not.toBeNull()
	})

	it('CLASSIC-02-gaze translates pupils on mousemove inside wrapper', async () => {
		const root = mount()
		const wrapper = root.querySelector('.virtuprof-avatar-wrapper, .prof-lern-avatar')
		expect(wrapper).not.toBeNull()
		wrapper.getBoundingClientRect = () => ({ left: 0, top: 0, width: 60, height: 80, right: 60, bottom: 80, x: 0, y: 0 })
		const evt = new MouseEvent('mousemove', { clientX: 60, clientY: 80, bubbles: true })
		wrapper.dispatchEvent(evt)
		await new Promise(resolve => setTimeout(resolve, 0))
		const pupils = root.querySelector('svg g.pupils, svg g[data-prof-feature="pupils"]')
		expect(pupils).not.toBeNull()
		const style = pupils.getAttribute('style') || ''
		expect(style).toMatch(/translate\(/)
		expect(style).not.toMatch(/translate\(0px,\s*0px\)/)
	})

	it('CLASSIC-02-gaze-gate gaze handler early-returns when prefers-reduced-motion: reduce', async () => {
		window.matchMedia = vi.fn().mockImplementation(query => ({
			matches: query === '(prefers-reduced-motion: reduce)',
			media: query,
			onchange: null,
			addEventListener: vi.fn(),
			removeEventListener: vi.fn(),
			dispatchEvent: vi.fn(),
		}))
		const root = mount()
		const wrapper = root.querySelector('.virtuprof-avatar-wrapper, .prof-lern-avatar')
		wrapper.getBoundingClientRect = () => ({ left: 0, top: 0, width: 60, height: 80, right: 60, bottom: 80, x: 0, y: 0 })
		const evt = new MouseEvent('mousemove', { clientX: 60, clientY: 80, bubbles: true })
		wrapper.dispatchEvent(evt)
		await new Promise(resolve => setTimeout(resolve, 0))
		const pupils = root.querySelector('svg g.pupils, svg g[data-prof-feature="pupils"]')
		const style = pupils?.getAttribute('style') || ''
		expect(style === '' || /translate\(0px,\s*0px\)/.test(style) || !/translate\(/.test(style)).toBe(true)
	})

	it('CLASSIC-02-wave click sets is-waving class which auto-removes after 1.2s', async () => {
		vi.useFakeTimers()
		const root = mount()
		const wrapper = root.querySelector('.virtuprof-avatar-wrapper, .prof-lern-avatar')
		wrapper.click()
		await Promise.resolve()
		const wavingNow = wrapper.querySelector('.is-waving') || (wrapper.classList && wrapper.classList.contains('is-waving')) || wrapper.querySelector('.virtuprof-avatar.is-waving')
		expect(wavingNow).toBeTruthy()
		vi.advanceTimersByTime(1300)
		await Promise.resolve()
		const stillWaving = wrapper.querySelector('.is-waving') || (wrapper.classList && wrapper.classList.contains('is-waving')) || wrapper.querySelector('.virtuprof-avatar.is-waving')
		expect(stillWaving).toBeFalsy()
	})

	it('CLASSIC-02 SVG root carries role="img" and a static aria-label (Phase 150 A11Y-03)', () => {
		const root = mount()
		const svg = root.querySelector('svg')
		expect(svg.getAttribute('role')).toBe('img')
		const label = svg.getAttribute('aria-label') || ''
		expect(label.length).toBeGreaterThan(0)
		expect(label).not.toMatch(/idle|wave|waving|talk|celebrate/i)
	})
})
