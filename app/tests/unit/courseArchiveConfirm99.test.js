/**
 * GitHub #99: "Archive" in the course list explains first what archiving changes, with
 * "Nicht mehr anzeigen" stored per user on the server (archive_confirm_skip). With it set, the
 * click archives directly as before. "Abbrechen"/Escape archive nothing; "Activate" stays one click.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { skip: 'no', personalFails: false, courses: [] } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			if (url === '/apps/learning/api/settings/personal') {
				if (api.personalFails) throw Object.assign(new Error('http'), { response: { status: 500 } })
				return { data: { archive_confirm_skip: api.skip } }
			}
			if (url === '/apps/learning/api/courses') return { data: { own: api.courses, enrolled: [] } }
			return { data: {} }
		}),
		post: vi.fn(async () => ({ data: {} })),
		put: vi.fn(async () => ({ data: {} })),
		delete: vi.fn(async () => ({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, p = {}) => url.replace(/\{(\w+)\}/g, (_, k) => String(p[k]))) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { stub } = vi.hoisted(() => ({ stub: (name, template = '<div><slot /></div>') => ({ default: { name, inheritAttrs: false, template } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('@nextcloud/vue/components/NcTextField', () => stub('NcTextField'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcActions', () => stub('NcActions'))
vi.mock('@nextcloud/vue/components/NcActionButton', () => ({ default: { name: 'NcActionButton', emits: ['click'], template: '<button type="button" class="action" @click="$emit(\'click\', $event)"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}))
// The real AccessibleDialog wraps NcDialog (teleport); the stub keeps name, body and actions in place.
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: {
		name: 'AccessibleDialog',
		props: ['name'],
		emits: ['closing'],
		template: '<div role="dialog" :aria-label="name"><slot /><slot name="actions" /><button type="button" data-test="escape" @click="$emit(\'closing\')">Esc</button></div>',
	},
}))

import axios from '@nextcloud/axios'

const flush = async () => { for (let i = 0; i < 4; i++) { await new Promise((r) => setTimeout(r, 0)); await nextTick() } }

let app
let host
let vm

async function mountList() {
	const { default: CourseList } = await import('../../src/components/CourseList.vue')
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(CourseList, { userRole: 'instructor', ref: 'list' }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	const root = app.mount(host)
	vm = root.$refs.list
	await flush()
}

const course = { id: 7, title: 'Brandschutz & Co', status: 'active', pool_count: 0, member_count: 0 }
const archiveButton = () => [...host.querySelectorAll('[data-course-id="7"] button.action')].find((b) => b.textContent.includes('Archive'))
const statusPuts = () => axios.put.mock.calls.filter(([url]) => url === '/apps/learning/api/courses/7')
const settingPuts = () => axios.put.mock.calls.filter(([url]) => url === '/apps/learning/api/settings/personal/archive-confirm')
const dialog = () => host.querySelector('[data-test="archive-confirm"]')

beforeEach(() => {
	vi.clearAllMocks()
	api.skip = 'no'
	api.personalFails = false
	api.courses = [{ ...course }]
})

afterEach(() => {
	app?.unmount()
	host?.remove()
})

describe('Archive confirmation in the course list (GitHub #99)', () => {
	it('"Archive" opens the explanation instead of archiving', async () => {
		await mountList()
		archiveButton().click()
		await flush()

		expect(dialog()).not.toBeNull()
		expect(dialog().getAttribute('aria-label')).toBe('Kurs „Brandschutz & Co“ archivieren?')
		expect(dialog().querySelector('[data-test="course-archive-explanation"]')?.textContent).toContain('Prüfungen ablegen, die wie bisher zählen')
		expect(dialog().textContent).toContain('Du kannst den Kurs jederzeit über das Menü in der Kursliste wieder aktivieren.')
		expect(dialog().textContent).toContain('Nicht mehr anzeigen')
		// a screen reader hears the explanation with the dialog name
		const described = dialog().getAttribute('aria-describedby')
		expect(described).toBeTruthy()
		expect(host.querySelector('#' + described)?.textContent).toContain('Lernende bekommen nicht mehr:')
		// the checkbox is not inside the text, so the first focus does not land on it
		expect(host.querySelector('#' + described + ' input[type="checkbox"]')).toBeNull()
		expect(statusPuts()).toHaveLength(0)
	})

	it('"Abbrechen" and Escape archive nothing and give focus back to the course', async () => {
		await mountList()
		archiveButton().click()
		await flush()
		host.querySelector('[data-test="archive-confirm-cancel"]').click()
		await flush()
		expect(dialog()).toBeNull()
		expect(document.activeElement).toBe(host.querySelector('[data-course-id="7"] .course-card-open'))

		archiveButton().click()
		await flush()
		host.querySelector('[data-test="escape"]').click()
		await flush()
		expect(dialog()).toBeNull()
		expect(statusPuts()).toHaveLength(0)
		expect(settingPuts()).toHaveLength(0)
	})

	it('confirming archives the course; the setting is left alone without the checkbox', async () => {
		await mountList()
		archiveButton().click()
		await flush()
		host.querySelector('[data-test="archive-confirm-ok"]').click()
		await flush()

		expect(statusPuts()).toEqual([['/apps/learning/api/courses/7', { status: 'archived' }]])
		expect(settingPuts()).toHaveLength(0)
		expect(dialog()).toBeNull()
	})

	it('"Nicht mehr anzeigen" is saved on the server, and the next "Archive" archives directly', async () => {
		api.courses = [{ ...course }, { ...course, id: 8, title: 'Zweiter Kurs' }]
		await mountList()
		archiveButton().click()
		await flush()
		const box = host.querySelector('[data-test="archive-confirm"] input[type="checkbox"]')
		box.checked = true
		box.dispatchEvent(new Event('change'))
		await flush()
		host.querySelector('[data-test="archive-confirm-ok"]').click()
		await flush()

		expect(settingPuts()).toEqual([['/apps/learning/api/settings/personal/archive-confirm', { skip: true }]])
		expect(statusPuts()).toHaveLength(1)

		await vm.toggleStatus({ ...course, id: 8 })
		await flush()
		expect(dialog()).toBeNull()
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/8', { status: 'archived' })
	})

	it('with the setting stored, "Archive" archives directly, as before', async () => {
		api.skip = 'yes'
		await mountList()
		archiveButton().click()
		await flush()

		expect(dialog()).toBeNull()
		expect(statusPuts()).toEqual([['/apps/learning/api/courses/7', { status: 'archived' }]])
	})

	it('a failed read of the setting shows the explanation rather than archiving unexplained', async () => {
		api.skip = 'yes'
		api.personalFails = true
		await mountList()
		archiveButton().click()
		await flush()

		expect(dialog()).not.toBeNull()
		expect(statusPuts()).toHaveLength(0)
	})

	it('"Activate" stays one click and reads no setting', async () => {
		api.courses = [{ ...course, status: 'archived' }]
		await mountList()
		const activate = [...host.querySelectorAll('[data-course-id="7"] button.action')].find((b) => b.textContent.includes('Activate'))
		activate.click()
		await flush()

		expect(dialog()).toBeNull()
		expect(axios.get).not.toHaveBeenCalledWith('/apps/learning/api/settings/personal')
		expect(statusPuts()).toEqual([['/apps/learning/api/courses/7', { status: 'active' }]])
	})
})
