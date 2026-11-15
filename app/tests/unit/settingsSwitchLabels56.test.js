/**
 * GitHub #56: every switch on the Learning settings pages sat on its own line under a separate
 * <label>. The label had no `for` and NcCheckboxRadioSwitch got an empty slot, so the switch was
 * also nameless for screen readers. The Nextcloud pattern (and CourseTabVerwaltung) puts the text
 * into the switch's default slot: switch and label on one line, label = accessible name.
 *
 * Mounts the real AdminSettings.vue and PersonalSettings.vue with a stub that renders the slot
 * and the description prop, and checks that no switch is left without a name.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
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
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton', '<button type="button"><slot /></button>'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon', '<span />'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard', '<div><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcSelect', () => stub('NcSelect', '<div />'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch',
	'<span class="switch-stub"><input type="checkbox" :checked="modelValue"><span class="switch-stub__label"><slot /></span>'
	+ '<span v-if="description" class="switch-stub__desc">{{ description }}</span></span>',
	{ props: ['modelValue', 'type', 'description', 'disabled'], emits: ['update:modelValue'] }))
vi.mock('../../src/components/PrivacyInfo.vue', () => stub('PrivacyInfo', '<div />'))
vi.mock('../../src/components/SkinOptionPreview.vue', () => stub('SkinOptionPreview', '<span />'))
vi.mock('../../src/components/OperatorSettings.vue', () => stub('OperatorSettings', '<div />'))

import AdminSettings from '../../src/components/AdminSettings.vue'
import PersonalSettings from '../../src/components/PersonalSettings.vue'

let host, app

async function settle() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mount(component, props = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(component, props)
	app.use(pinia)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await settle()
}

function switches() {
	return [...host.querySelectorAll('.switch-stub')]
}

beforeEach(() => {
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('#56 settings switches carry their own label', () => {
	it('AdminSettings: every switch has a name in its slot', async () => {
		await mount(AdminSettings)
		const all = switches()
		expect(all.length).toBeGreaterThanOrEqual(4)
		for (const el of all) {
			expect(el.querySelector('.switch-stub__label').textContent.trim()).not.toBe('')
		}
		const names = all.map((el) => el.querySelector('.switch-stub__label').textContent.trim())
		expect(names).toContain('Gamification enabled')
		expect(names).toContain('Allow course-based instructor fallback')
	})

	it('AdminSettings: the help text of a switch is its description, not a loose line', async () => {
		await mount(AdminSettings)
		const fallback = switches().find((el) => el.textContent.includes('Allow course-based instructor fallback'))
		expect(fallback.querySelector('.switch-stub__desc').textContent).toContain('haben diese Rechte nur die oben gewählten Gruppen und Personen')
	})

	it('PersonalSettings (instructor): every switch has a name in its slot', async () => {
		await mount(PersonalSettings, { userRole: 'instructor' })
		const all = switches()
		expect(all.length).toBeGreaterThanOrEqual(5)
		for (const el of all) {
			expect(el.querySelector('.switch-stub__label').textContent.trim()).not.toBe('')
		}
		const names = all.map((el) => el.querySelector('.switch-stub__label').textContent.trim())
		expect(names).toContain('Show student view')
		expect(names).toContain('Virtual assistant')
		expect(names).toContain('Ruhige Darstellung (keine Avatar-Animationen)')
	})

	it('PersonalSettings: no switch row keeps a separate <label> above the switch', async () => {
		await mount(PersonalSettings, { userRole: 'instructor' })
		for (const el of switches()) {
			const row = el.parentElement
			expect(row.querySelector(':scope > label')).toBeNull()
		}
	})
})
