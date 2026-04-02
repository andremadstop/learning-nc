import { beforeEach, describe, expect, it, vi } from 'vitest'

import { focusFirstElement, getFocusableElements, trapTabKey } from '../../src/utils/dialogFocus.js'

describe('dialogFocus', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('returns visible focusable elements in DOM order', () => {
		document.body.innerHTML = `
			<div id="root">
				<button id="first">First</button>
				<button id="hidden" hidden>Hidden</button>
				<input id="second" />
			</div>
		`

		const ids = getFocusableElements(document.getElementById('root')).map((element) => element.id)

		expect(ids).toEqual(['first', 'second'])
	})

	it('focuses the first element or falls back to the container', () => {
		document.body.innerHTML = `
			<div id="root" tabindex="-1">
				<button id="first">First</button>
			</div>
		`

		const root = document.getElementById('root')
		const first = document.getElementById('first')

		expect(focusFirstElement(root, root)).toBe(first)
		expect(document.activeElement).toBe(first)
	})

	it('cycles focus inside the root on tab and shift-tab', () => {
		document.body.innerHTML = `
			<div id="root" tabindex="-1">
				<button id="first">First</button>
				<button id="last">Last</button>
			</div>
		`

		const root = document.getElementById('root')
		const first = document.getElementById('first')
		const last = document.getElementById('last')

		last.focus()
		const forwardEvent = { key: 'Tab', shiftKey: false, preventDefault: vi.fn() }
		expect(trapTabKey(forwardEvent, root, root)).toBe(true)
		expect(forwardEvent.preventDefault).toHaveBeenCalledTimes(1)
		expect(document.activeElement).toBe(first)

		first.focus()
		const backwardEvent = { key: 'Tab', shiftKey: true, preventDefault: vi.fn() }
		expect(trapTabKey(backwardEvent, root, root)).toBe(true)
		expect(backwardEvent.preventDefault).toHaveBeenCalledTimes(1)
		expect(document.activeElement).toBe(last)
	})
})
