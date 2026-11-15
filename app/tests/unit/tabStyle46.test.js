/**
 * GitHub #46: one tab look and one width rule for the whole app.
 *
 * The course mega-tabs had a blue underline while the pool window, the settings, the course list
 * and the pool list had a filled segment, and the segments stretched every tab (flex: 1), so two
 * tabs took half the screen each. All of them now use .lnc-tablist / .lnc-tab from
 * src/styles/tabs.css; the components keep only their margins.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const SRC = resolve(__dirname, '../../src')

function read(rel) {
	return readFileSync(resolve(SRC, rel), 'utf8')
}

function styleOf(rel) {
	const sfc = read(rel)
	const blocks = [...sfc.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1])
	return blocks.join('\n').replace(/\/\*[\s\S]*?\*\//g, '')
}

/** Declarations of every rule (any media block) whose selector list contains `selector`. */
function declsFor(css, selector) {
	const out = []
	for (const m of css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const selectors = m[1].split(',').map((s) => s.trim().replace(/\s+/g, ' '))
		if (!selectors.includes(selector)) continue
		const d = {}
		for (const decl of m[2].split(';')) {
			const i = decl.indexOf(':')
			if (i < 0) continue
			d[decl.slice(0, i).trim()] = decl.slice(i + 1).trim()
		}
		out.push(d)
	}
	return out
}

const VISUAL = ['background', 'color', 'flex', 'max-width', 'border-bottom', 'border-bottom-color', 'flex-direction', 'padding']

describe('#46 shared tab style', () => {
	const tabs = read('styles/tabs.css')

	it('main.js loads the shared tab style', () => {
		expect(read('main.js')).toMatch(/import '\.\/styles\/tabs\.css'/)
	})

	it('the tab bar is as wide as its tabs and wraps', () => {
		const [bar] = declsFor(tabs, '.lnc-tablist')
		expect(bar.width).toBe('fit-content')
		expect(bar['max-width']).toBe('100%')
		expect(bar['flex-wrap']).toBe('wrap')
	})

	it('a tab is not stretched and the active one is the filled primary segment', () => {
		const [tab] = declsFor(tabs, '.lnc-tab')
		expect(tab.flex).toBe('0 1 auto')
		const [active] = declsFor(tabs, '.lnc-tab.active')
		expect(active.background).toBe('var(--color-primary-element)')
		expect(active.color).toBe('var(--color-primary-element-text)')
		// hover never repaints the active tab
		expect(declsFor(tabs, '.lnc-tab:hover')).toEqual([])
		expect(declsFor(tabs, '.lnc-tab:hover:not(.active)').length).toBe(1)
	})

	it.each([
		['components/CourseDetail.vue', ['.mega-tab-selector', '.mega-tab-button', '.mega-tab-button.active', '.mega-tab-button:hover']],
		['App.vue', ['.mode-selector', '.mode-btn', '.mode-btn.active', '.mode-btn:hover', '.course-sub-nav']],
		['components/PoolList.vue', ['.pool-tabs', '.tab-btn', '.tab-btn.active', '.tab-btn:hover']],
	])('%s keeps no own tab look or width', (file, selectors) => {
		const css = styleOf(file)
		for (const selector of selectors) {
			for (const d of declsFor(css, selector)) {
				for (const prop of VISUAL) {
					expect(d[prop], `${file} ${selector} ${prop}`).toBeUndefined()
				}
			}
		}
	})

	it.each([
		['components/CourseDetail.vue', 'mega-tab-selector', 'mega-tab-button'],
		['App.vue', 'mode-selector', 'mode-btn'],
		['components/PoolList.vue', 'pool-tabs', 'tab-btn'],
	])('%s uses the shared classes', (file, bar, tab) => {
		const sfc = read(file)
		expect(sfc).toMatch(new RegExp(`class="${bar} lnc-tablist"`))
		expect(sfc).toMatch(new RegExp(`'${tab}', 'lnc-tab'|class="${tab} lnc-tab"`))
	})

	it('the settings and course list sub-navigation use the shared bar', () => {
		const sfc = read('App.vue')
		expect(sfc.match(/class="course-sub-nav lnc-tablist"/g)?.length).toBe(2)
	})
})
