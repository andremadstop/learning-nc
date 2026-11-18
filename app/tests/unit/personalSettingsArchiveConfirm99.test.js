/**
 * GitHub #99 review: "Nicht mehr anzeigen" in the archive confirmation set archive_confirm_skip,
 * and nothing in the UI set it back. PersonalSettings now has "Vor dem Archivieren erklären"
 * for instructors. ON means the dialog explains first, i.e. skip=false: the inverse of the stored
 * flag, the same shape that once turned the calm-display switch upside down (S29).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

const { api } = vi.hoisted(() => ({ api: { skip: 'no' } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/settings/personal')) {
				return Promise.resolve({ data: { archive_confirm_skip: api.skip } })
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))
const { stub } = vi.hoisted(() => ({
	stub: (name, template, extra = {}) => ({ default: { name, template, ...extra } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton', '<button type="button" class="nc-button-stub" @click="$emit(\'click\')"><slot /></button>', { emits: ['click'] }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon', '<span />'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard', '<div><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcSelect', () => stub('NcSelect', '<div />'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch',
	'<label><input type="checkbox" class="switch-stub" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	{ props: ['modelValue', 'type', 'description'], emits: ['update:modelValue'] }))
vi.mock('../../src/components/PrivacyInfo.vue', () => stub('PrivacyInfo', '<div />'))
vi.mock('../../src/components/SkinOptionPreview.vue', () => stub('SkinOptionPreview', '<span />'))

import axios from '@nextcloud/axios'
import PersonalSettings from '../../src/components/PersonalSettings.vue'

let host, app

async function settle() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mountSettings(userRole) {
	const pinia = createPinia()
	setActivePinia(pinia)
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(PersonalSettings, { userRole }) })
	app.use(pinia)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await settle()
}

const archiveSwitch = () => host.querySelector('.archive-confirm-setting .switch-stub')
const archivePuts = () => axios.put.mock.calls.filter(([url]) => url === '/apps/learning/api/settings/personal/archive-confirm')

async function toggleAndSave(checked) {
	const input = archiveSwitch()
	input.checked = checked
	input.dispatchEvent(new Event('change'))
	await settle()
	;[...host.querySelectorAll('.nc-button-stub')].find((b) => b.textContent.trim() === 'Save').click()
	await settle()
}

beforeEach(() => {
	vi.clearAllMocks()
	api.skip = 'no'
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('PersonalSettings: "Vor dem Archivieren erklären" (GitHub #99)', () => {
	it('is ON by default and OFF after "Nicht mehr anzeigen"', async () => {
		await mountSettings('instructor')
		expect(host.querySelector('.archive-confirm-setting').textContent).toContain('Vor dem Archivieren erklären')
		expect(archiveSwitch().checked).toBe(true)
		app.unmount(); host.remove()

		api.skip = 'yes'
		await mountSettings('instructor')
		expect(archiveSwitch().checked).toBe(false)
	})

	it('switching it back ON sends skip=false, which brings the dialog back', async () => {
		api.skip = 'yes'
		await mountSettings('instructor')
		await toggleAndSave(true)
		expect(archivePuts()).toHaveLength(1)
		expect(archivePuts()[0][1]).toEqual({ skip: false })
	})

	it('switching it OFF sends skip=true', async () => {
		await mountSettings('instructor')
		await toggleAndSave(false)
		expect(archivePuts()).toHaveLength(1)
		expect(archivePuts()[0][1]).toEqual({ skip: true })
	})

	it('saving without a change does not write the flag', async () => {
		await mountSettings('instructor')
		;[...host.querySelectorAll('.nc-button-stub')].find((b) => b.textContent.trim() === 'Save').click()
		await settle()
		expect(archivePuts()).toHaveLength(0)
	})

	it('students do not see it and never write it', async () => {
		await mountSettings('student')
		expect(host.querySelector('.archive-confirm-setting')).toBeNull()
		;[...host.querySelectorAll('.nc-button-stub')].find((b) => b.textContent.trim() === 'Save').click()
		await settle()
		expect(archivePuts()).toHaveLength(0)
	})
})
