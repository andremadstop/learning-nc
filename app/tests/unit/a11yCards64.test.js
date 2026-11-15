/**
 * GitHub #64 (axe nested-interactive): a course card holds the action menu, so the card itself is
 * no longer a role="button"; its title is a real button. Mounted CourseList: the title opens the
 * course exactly once (the card's own mouse click must not fire a second time), the action menu
 * no longer sits inside a button, and the card still opens on a mouse click anywhere.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), text)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { stub } = vi.hoisted(() => ({
	stub: (name, tag = 'div', cls = '') => ({ default: { name, template: `<${tag} class="${cls}"><slot /></${tag}>` } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton', 'button'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('@nextcloud/vue/components/NcTextField', () => stub('NcTextField'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcActions', () => stub('NcActions', 'div', 'nc-actions'))
vi.mock('@nextcloud/vue/components/NcActionButton', () => stub('NcActionButton', 'button', 'nc-action-button'))

import axios from '@nextcloud/axios'
import CourseList from '../../src/components/CourseList.vue'

let app = null
let host = null

async function mount(onSelect) {
	axios.get.mockResolvedValue({ data: { own: [{ id: 3, title: 'Brandschutz', status: 'active' }], enrolled: [] } })
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(CourseList, { userRole: 'instructor', onSelectCourse: onSelect }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = (a, s, p, c) => (c === 1 ? s : p)
	app.config.warnHandler = () => {}
	app.mount(host)
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
	vi.clearAllMocks()
})

describe('#64 course card without nested controls', () => {
	it('the card is no button; the title is, and it opens the course once', async () => {
		const onSelect = vi.fn()
		await mount(onSelect)
		const card = host.querySelector('.course-card')
		expect(card).not.toBeNull()
		expect(card.getAttribute('role')).toBeNull()
		expect(card.hasAttribute('tabindex')).toBe(false)

		const open = card.querySelector('button.course-card-open')
		expect(open.textContent.trim()).toBe('Brandschutz')
		open.click()
		expect(onSelect).toHaveBeenCalledTimes(1)
		expect(onSelect.mock.calls[0][0].id).toBe(3)
	})

	it('the action menu sits in no role="button"', async () => {
		await mount(vi.fn())
		const menu = host.querySelector('.course-card .nc-actions')
		expect(menu).not.toBeNull()
		expect(menu.closest('[role="button"]')).toBeNull()
	})

	it('a mouse click elsewhere on the card still opens the course', async () => {
		const onSelect = vi.fn()
		await mount(onSelect)
		host.querySelector('.course-card .course-meta').click()
		expect(onSelect).toHaveBeenCalledTimes(1)
	})
})
