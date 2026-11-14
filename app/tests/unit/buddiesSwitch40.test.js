import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

import axios from '@nextcloud/axios'
import { createApp, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { buddiesEnabled, kommunikationTabIds } from '../../src/utils/courseView.js'
import { LEARNING_PROFILE_SECTION } from '../../src/utils/telosProfile.js'
import { setTestLanguage } from './support/l10nCatalog.js'
import BuddyMatching from '../../src/components/BuddyMatching.vue'

// GitHub #40 (e): learning partners get a course switch (mode_config.buddies, default on), and
// the empty state explains the feature and opens the learning profile instead of naming a
// "Telos profile" the app shows nowhere.
describe('kommunikationTabIds — learning partner switch (GitHub #40)', () => {
	it('keeps the learning partners for a course saved before the switch existed', () => {
		expect(kommunikationTabIds({ mode_config: {} })).toEqual(['feed', 'my-questions', 'buddies'])
		expect(kommunikationTabIds({ mode_config: null }, { aiEnabled: true })).toEqual(['feed', 'my-questions', 'buddies', 'schwarm'])
		expect(kommunikationTabIds(null)).toEqual(['feed', 'my-questions', 'buddies'])
		expect(buddiesEnabled(null)).toBe(true)
	})

	it('drops the learning partners when the course switched them off', () => {
		expect(kommunikationTabIds({ mode_config: { buddies: false } })).toEqual(['feed', 'my-questions'])
		expect(kommunikationTabIds({ mode_config: { buddies: false } }, { aiEnabled: true })).toEqual(['feed', 'my-questions', 'schwarm'])
		expect(buddiesEnabled({ mode_config: { buddies: false } })).toBe(false)
	})

	it('leaves the instructor leaves unchanged', () => {
		expect(kommunikationTabIds({ mode_config: { buddies: false } }, { actsAsInstructor: true })).toEqual(['announcements', 'feed', 'requests'])
	})
})

function mount(component, props, globalProperties = {}) {
	const el = document.createElement('div')
	document.body.appendChild(el)
	const app = createApp(component, props)
	Object.assign(app.config.globalProperties, globalProperties)
	const vm = app.mount(el)
	return { vm, el, unmount: () => { app.unmount(); el.remove() } }
}

async function flush() {
	for (let i = 0; i < 3; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('BuddyMatching empty state (GitHub #40)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setTestLanguage('de')
	})

	it('explains learning partners without "Telos" and opens the learning profile', async () => {
		axios.get.mockResolvedValue({ data: { can_help_me: [], i_can_help: [] } })
		const push = vi.fn()
		const { el, unmount } = mount(BuddyMatching, { courseId: 5 }, { $router: { push } })
		await flush()

		const text = el.querySelector('.buddy-empty').textContent
		expect(text).not.toMatch(/Telos/i)
		expect(text).toContain('Lernprofil')
		expect(text).toContain('Ich kann helfen bei...')
		expect(text).toContain('Ich brauche Hilfe bei...')
		expect(text).toContain('Sichtbarkeit')

		const button = el.querySelector('.buddy-empty__profile')
		expect(button.textContent).toContain('Lernprofil öffnen')
		button.click()
		expect(push).toHaveBeenCalledWith({ name: 'settings', query: { section: LEARNING_PROFILE_SECTION } })
		unmount()
	})

	it('names the settings fields the way the profile form shows them, in every language', () => {
		// The empty state quotes the form labels; a translation that renames them would send the
		// learner looking for a field that does not exist.
		const body = 'Lernpartner sind andere Kursmitglieder, die dir bei einem Thema helfen können oder bei einem Thema Hilfe suchen, das du beherrschst. Trage dazu in deinem Lernprofil unter „Ich kann helfen bei...“ und „Ich brauche Hilfe bei...“ deine Themen ein und stelle die Sichtbarkeit auf „Kurs“. Ein Treffer entsteht, sobald ein anderes Kursmitglied dasselbe Thema in derselben Schreibweise eingetragen hat und sein Profil nicht auf „Privat“ steht.'
		for (const lang of ['de', 'en', 'fr', 'ru', 'ar', 'uk']) {
			const catalog = JSON.parse(readFileSync(resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations
			const translated = catalog[body]
			expect(translated, lang).toBeTruthy()
			for (const label of ['I can help with...', 'I need help with...', 'Course', 'Private']) {
				expect(translated, `${lang}: ${label}`).toContain(catalog[label])
			}
		}
	})
})

describe('the learning profile link lands on the profile (GitHub #40)', () => {
	it('App.vue opens "Meine Einstellungen" for the learning-profile section', async () => {
		const source = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
		const settingsBranch = source.slice(source.indexOf("if (route.name === 'settings') {"))
		const block = settingsBranch.slice(0, settingsBranch.indexOf('return;'))
		expect(block).toMatch(/route\.query\?\.section === LEARNING_PROFILE_SECTION/)
		expect(block).toMatch(/this\.settingsSubTab = 'personal'/)
	})

	it('PersonalSettings scrolls to the profile heading only when the route asks for it', async () => {
		const { default: PersonalSettings } = await import('../../src/components/PersonalSettings.vue')
		const scrollIntoView = vi.fn()
		const make = (section) => ({
			$route: { query: section ? { section } : {} },
			$refs: { learningProfileHeading: { scrollIntoView } },
			$nextTick: (fn) => fn(),
		})

		PersonalSettings.methods.scrollToRequestedSection.call(make(LEARNING_PROFILE_SECTION))
		expect(scrollIntoView).toHaveBeenCalledTimes(1)

		PersonalSettings.methods.scrollToRequestedSection.call(make(null))
		PersonalSettings.methods.scrollToRequestedSection.call(make('other'))
		PersonalSettings.methods.scrollToRequestedSection.call({ $route: undefined, $refs: {}, $nextTick: (fn) => fn() })
		expect(scrollIntoView).toHaveBeenCalledTimes(1)

		const source = readFileSync(resolve(__dirname, '../../src/components/PersonalSettings.vue'), 'utf8')
		expect(source).toMatch(/<h3 id="learning-profile" ref="learningProfileHeading">/)
		expect(source).toMatch(/this\.load\(\)\.then\(\(\) => this\.scrollToRequestedSection\(\)\)/)
	})
})

describe('CourseTabTeilnehmer class profile follows the switch (GitHub #40)', () => {
	it('renders the learning partners there only while the course has them on', () => {
		const source = readFileSync(resolve(__dirname, '../../src/components/CourseTabTeilnehmer.vue'), 'utf8')
		expect(source).toMatch(/<BuddyMatching v-if="buddiesOn" :course-id="courseId" \/>/)
	})
})
