/**
 * #46 review: the shared arrow-key handler for every tab bar.
 * In a right-to-left layout (Arabic) the first tab is the rightmost one, so ArrowLeft must move
 * to the next tab, not wrap to the last one.
 */
import { afterEach, describe, expect, it } from 'vitest'
import { moveTablistFocus } from '../../src/utils/tablistKeydown.js'

function mountTablist(dir) {
	const bar = document.createElement('div')
	bar.setAttribute('role', 'tablist')
	if (dir) bar.setAttribute('dir', dir)
	bar.style.direction = dir || 'ltr'
	for (const label of ['one', 'two', 'three']) {
		const tab = document.createElement('button')
		tab.setAttribute('role', 'tab')
		tab.textContent = label
		bar.appendChild(tab)
	}
	document.body.appendChild(bar)
	return { bar, tabs: [...bar.children] }
}

function press(bar, key) {
	const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true })
	Object.defineProperty(event, 'currentTarget', { value: bar })
	moveTablistFocus(event)
	return event
}

afterEach(() => {
	document.body.innerHTML = ''
})

describe('moveTablistFocus', () => {
	it('left-to-right: ArrowRight is the next tab, ArrowLeft wraps to the last', () => {
		const { bar, tabs } = mountTablist()
		tabs[0].focus()
		expect(press(bar, 'ArrowRight').defaultPrevented).toBe(true)
		expect(document.activeElement).toBe(tabs[1])
		tabs[0].focus()
		press(bar, 'ArrowLeft')
		expect(document.activeElement).toBe(tabs[2])
	})

	it('right-to-left: ArrowLeft is the visually adjacent next tab, ArrowRight the previous', () => {
		const { bar, tabs } = mountTablist('rtl')
		tabs[0].focus()
		press(bar, 'ArrowLeft')
		expect(document.activeElement).toBe(tabs[1])
		press(bar, 'ArrowRight')
		expect(document.activeElement).toBe(tabs[0])
		press(bar, 'ArrowRight')
		expect(document.activeElement).toBe(tabs[2])
	})

	it('Up/Down keep their meaning in right-to-left', () => {
		const { bar, tabs } = mountTablist('rtl')
		tabs[0].focus()
		press(bar, 'ArrowDown')
		expect(document.activeElement).toBe(tabs[1])
		press(bar, 'ArrowUp')
		expect(document.activeElement).toBe(tabs[0])
	})

	it('ignores other keys and focus outside the tab bar', () => {
		const { bar, tabs } = mountTablist()
		tabs[1].focus()
		expect(press(bar, 'Enter').defaultPrevented).toBe(false)
		expect(document.activeElement).toBe(tabs[1])
		tabs[1].blur()
		expect(press(bar, 'ArrowRight').defaultPrevented).toBe(false)
	})
})
