/**
 * GitHub #35: the course administration rendered for real.
 *
 * CourseTabVerwaltung.test.js calls methods on a plain object and cannot see a v-if. These tests
 * mount the component, so a dropped gate, description or request key turns them red.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})

const { api } = vi.hoisted(() => ({ api: { schedule: null } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/settings/tools') return Promise.resolve({ data: { enabled_tools: null } })
			if (url === '/apps/learning/api/courses/5/tools') return Promise.resolve({ data: { enabled_tools: null } })
			if (url === '/apps/learning/api/story/campaigns') return Promise.resolve({ data: { campaigns: [{ campaign_id: 'wannacry', title: 'WannaCry', difficulty: 'easy' }] } })
			if (url === '/apps/learning/api/courses/5/schedule') return api.schedule instanceof Error ? Promise.reject(api.schedule) : Promise.resolve({ data: api.schedule })
			return Promise.resolve({ data: {} })
		}),
		put: vi.fn((url, body) => {
			if (url.endsWith('/mode-config')) return Promise.resolve({ data: { mode_config: body.modeConfig } })
			if (url.endsWith('/tools')) return Promise.resolve({ data: { enabled_tools: body.enabledTools } })
			if (url.endsWith('/schedule')) return Promise.resolve({ data: { schedule: body.entries } })
			return Promise.resolve({ data: {} })
		}),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))

import axios from '@nextcloud/axios'
import CourseTabVerwaltung from '../../src/components/CourseTabVerwaltung.vue'

const SAVED_MODE_CONFIG = {
	training: true,
	leitner: true,
	exam: true,
	duel: true,
	gameshow: true,
	league: true,
	abenteuer: false,
	course_summary: false,
	tools: true,
}

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('CourseTabVerwaltung rendered (GitHub #35)', () => {
	let host, app

	async function mountTab({ activeTab = 'mode-config', modeConfig = {}, courseExtra = {} } = {}) {
		host = document.createElement('div')
		document.body.appendChild(host)
		const tabRef = ref(null)
		const course = {
			id: 5,
			is_instructor: true,
			mode_config: { ...SAVED_MODE_CONFIG, ...modeConfig },
			leitner_sprint: false,
			exam_date: null,
			...courseExtra,
		}
		app = createApp({
			render: () => h(CourseTabVerwaltung, { ref: tabRef, courseId: 5, course, coursePools: [], userRole: 'instructor', actsAsInstructor: true, activeTab }),
		})
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		return tabRef.value
	}

	function modeCheckbox(label) {
		const row = Array.from(host.querySelectorAll('.mode-toggle-row--described')).find((r) => r.querySelector('label').textContent.trim() === label)
		expect(row, `mode row ${label}`).toBeTruthy()
		return row.querySelector('input[type="checkbox"]')
	}

	async function toggle(input, checked) {
		input.checked = checked
		input.dispatchEvent(new Event('change'))
		await flush()
	}

	function buttonByText(text) {
		const button = Array.from(host.querySelectorAll('button')).find((b) => b.textContent.trim() === text)
		expect(button, `button ${text}`).toBeTruthy()
		return button
	}

	beforeEach(() => {
		vi.clearAllMocks()
		api.schedule = { schedule: [], chapters_in_scope: [], known_chapter_refs: [] }
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('describes every mode switch and links the description to its checkbox', async () => {
		await mountTab()

		const keys = ['training', 'leitner', 'exam', 'duel', 'gameshow', 'league', 'abenteuer', 'course_summary']
		for (const key of keys) {
			const desc = host.querySelector(`[data-test="mode-desc-${key}"]`)
			expect(desc, key).toBeTruthy()
			expect(desc.textContent.trim().length, key).toBeGreaterThan(20)
			expect(host.querySelector(`input[aria-describedby="mode-desc-${key}"]`), key).toBeTruthy()
		}
		expect(host.querySelector('[data-test="mode-desc-leitner"]').textContent).toContain('fünf Fächern')
		expect(host.querySelector('[data-test="gamification-note"]').textContent).toContain('Server-Administration')
	})

	it('shows the Leitner sprint setting only while Leitner is on', async () => {
		await mountTab()
		expect(host.querySelector('[data-test="leitner-sprint"]')).toBeTruthy()

		await toggle(modeCheckbox('Leitner'), false)
		expect(host.querySelector('[data-test="leitner-sprint"]')).toBeNull()
	})

	it('shows the adventure campaigns only while Adventure is on', async () => {
		await mountTab()
		expect(host.querySelector('[data-test="adventure-campaigns"]')).toBeNull()

		await toggle(modeCheckbox('Abenteuer'), true)
		expect(host.querySelector('[data-test="adventure-campaigns"]')).toBeTruthy()
	})

	it('hides the per-tool list while the tools master switch is off', async () => {
		await mountTab()
		expect(host.querySelector('[data-test="tool-list"]')).toBeTruthy()

		await toggle(host.querySelector('[data-test="tools-master"]'), false)
		expect(host.querySelector('[data-test="tool-list"]')).toBeNull()
	})

	it('a course stored with tools off opens with the switch off and the list hidden', async () => {
		await mountTab({ modeConfig: { tools: false } })

		expect(host.querySelector('[data-test="tools-master"]').checked).toBe(false)
		expect(host.querySelector('[data-test="tool-list"]')).toBeNull()
	})

	// Request body: CourseController::updateModeConfig reads `modeConfig`, CourseService stores `tools`.
	it('"Save tools" with the switch off stores tools:false in mode_config and leaves the tool selection alone', async () => {
		await mountTab()
		// An unsaved change to another mode must not ride along with the tools save.
		await toggle(modeCheckbox('Training'), false)
		await toggle(host.querySelector('[data-test="tools-master"]'), false)

		buttonByText('Save tools').click()
		await flush()

		const modeCalls = axios.put.mock.calls.filter(([url]) => url === '/apps/learning/api/courses/5/mode-config')
		expect(modeCalls).toHaveLength(1)
		expect(modeCalls[0][1]).toEqual({ modeConfig: { ...SAVED_MODE_CONFIG, tools: false } })
		expect(axios.put.mock.calls.some(([url]) => url === '/apps/learning/api/courses/5/tools')).toBe(false)
	})

	it('"Save tools" with the switch on stores tools:true and then the tool selection', async () => {
		await mountTab({ modeConfig: { tools: false } })
		await toggle(host.querySelector('[data-test="tools-master"]'), true)

		buttonByText('Save tools').click()
		await flush()

		const urls = axios.put.mock.calls.map(([url]) => url)
		expect(urls).toEqual(['/apps/learning/api/courses/5/mode-config', '/apps/learning/api/courses/5/tools'])
		expect(axios.put.mock.calls[0][1].modeConfig.tools).toBe(true)
	})

	it('the mode save carries the tools switch too', async () => {
		await mountTab()
		await toggle(host.querySelector('[data-test="tools-master"]'), false)

		// The first "Save" button belongs to the mode list.
		buttonByText('Save').click()
		await flush()

		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/5/mode-config', expect.objectContaining({
			modeConfig: expect.objectContaining({ tools: false }),
		}))
	})

	// Only exams started in the course count for the certificate. With the exam mode off nobody
	// could ever be certified, and nothing said so.
	describe('certification without the exam mode', () => {
		const CERT_ON = { cert_enabled: true, cert_pass_percent: 80, cert_required_pool_ids: [], cert_validity_days: 0 }
		const warning = () => host.querySelector('[data-test="cert-exam-mode-off"]')

		it('warns in the certificate settings while the stored exam mode is off', async () => {
			await mountTab({ modeConfig: { exam: false }, courseExtra: CERT_ON })

			expect(warning()).not.toBeNull()
			expect(warning().textContent).toContain('So kann niemand ein Zertifikat erhalten')
			expect(warning().closest('.cert-config')).not.toBeNull()
		})

		it('no warning while the exam mode is on, or while certification is off', async () => {
			await mountTab({ courseExtra: CERT_ON })
			expect(warning()).toBeNull()
			app.unmount()
			host.remove()

			await mountTab({ modeConfig: { exam: false }, courseExtra: { cert_enabled: false } })
			expect(warning()).toBeNull()
		})

		it('follows the stored modes: an unsaved untick does not warn, saving it does, saving it back clears it', async () => {
			await mountTab({ courseExtra: CERT_ON })

			await toggle(modeCheckbox('Prüfung'), false)
			expect(warning()).toBeNull()

			buttonByText('Save').click()
			await flush()
			expect(warning()).not.toBeNull()

			await toggle(modeCheckbox('Prüfung'), true)
			buttonByText('Save').click()
			await flush()
			expect(warning()).toBeNull()
		})

		it('refuses nothing: the certificate settings still save with the exam mode off', async () => {
			await mountTab({ modeConfig: { exam: false }, courseExtra: CERT_ON })

			host.querySelector('.cert-config button').click()
			await flush()

			expect(axios.patch).toHaveBeenCalledWith('/apps/learning/api/courses/5/cert-config', expect.objectContaining({ certEnabled: true }))
		})
	})

	// The sprint checkbox and the Talk token save on their own. They used to PUT modeConfigLocal,
	// which carries every switch changed on the page without its own Save, the tools master too.
	it('saving the sprint flag stores neither the unsaved tools switch nor another unsaved mode', async () => {
		await mountTab()
		await toggle(host.querySelector('[data-test="tools-master"]'), false)
		await toggle(modeCheckbox('Training'), false)

		await toggle(host.querySelector('[data-test="leitner-sprint"] input[type="checkbox"]'), true)

		const modeCalls = axios.put.mock.calls.filter(([url]) => url === '/apps/learning/api/courses/5/mode-config')
		expect(modeCalls).toHaveLength(1)
		expect(modeCalls[0][1]).toEqual({ modeConfig: SAVED_MODE_CONFIG, leitnerSprint: true })
	})

	it('saving the Talk token stores neither the unsaved tools switch nor another unsaved mode', async () => {
		await mountTab()
		await toggle(host.querySelector('[data-test="tools-master"]'), false)
		await toggle(modeCheckbox('Duell'), false)
		const tokenInput = host.querySelector('.talk-token-input')
		tokenInput.value = 'abc123'
		tokenInput.dispatchEvent(new Event('input'))
		await flush()

		host.querySelector('.talk-config button').click()
		await flush()

		const modeCalls = axios.put.mock.calls.filter(([url]) => url === '/apps/learning/api/courses/5/mode-config')
		expect(modeCalls).toHaveLength(1)
		expect(modeCalls[0][1]).toEqual({ modeConfig: SAVED_MODE_CONFIG, talkRoomToken: 'abc123' })
	})

	it('after a mode save, the sprint save sends the newly stored modes, not the ones from page load', async () => {
		await mountTab()
		await toggle(modeCheckbox('Training'), false)
		buttonByText('Save').click()
		await flush()
		axios.put.mockClear()

		await toggle(host.querySelector('[data-test="leitner-sprint"] input[type="checkbox"]'), true)

		const modeCalls = axios.put.mock.calls.filter(([url]) => url === '/apps/learning/api/courses/5/mode-config')
		expect(modeCalls[0][1].modeConfig).toEqual({ ...SAVED_MODE_CONFIG, training: false })
	})

	// MaintenanceService only feeds a counter card (StudentDashboard); its button opens the normal
	// Leitner queue. The previous hint promised a course-specific portion, most forgotten first.
	it('the maintenance hint describes the counter card and the normal queue, without a course-end promise', async () => {
		await mountTab()

		const hint = host.querySelector('[data-test="maintenance-hint"]').textContent
		expect(hint).toContain('Karte „Wartungsmodus“')
		expect(hint).toContain('normale Wiederholungs-Warteschlange')
		expect(hint).toContain('auch ohne den Modus')
		expect(hint).not.toContain('am meisten vergessenen')
		expect(hint).toContain('Ein Kursende wird nicht geprüft')
		expect(host.textContent).not.toContain('Maintenance Mode nach Kursende')
	})

	describe('schedule editor', () => {
		it('suggests the chapter keys of the course pools and sends the typed chapter_ref', async () => {
			api.schedule = {
				schedule: [{ chapter_ref: 'chapter-01', chapter_title: 'Basics', start_date: null, target_date: '2026-12-01', sort_order: 1 }],
				chapters_in_scope: [],
				known_chapter_refs: ['chapter-01', 'chapter-02'],
			}
			await mountTab({ activeTab: 'schedule' })

			const options = Array.from(host.querySelectorAll('datalist#schedule-chapter-refs option')).map((o) => o.value)
			expect(options).toEqual(['chapter-01', 'chapter-02'])
			const refInput = host.querySelector('[data-test="schedule-chapter-ref"]')
			expect(refInput.getAttribute('list')).toBe('schedule-chapter-refs')
			expect(refInput.value).toBe('chapter-01')
			expect(host.querySelector('[data-test="schedule-chapter-help"]').textContent).toContain('Kapitel-Schlüssel')

			refInput.value = 'chapter-02'
			refInput.dispatchEvent(new Event('input'))
			await flush()
			buttonByText('Zeitplan speichern').click()
			await flush()

			const call = axios.put.mock.calls.find(([url]) => url === '/apps/learning/api/courses/5/schedule')
			expect(call[1].entries[0]).toEqual(expect.objectContaining({ chapter_ref: 'chapter-02', chapter_title: 'Basics' }))
		})

		// The table's unique index (course_id, chapter_ref) made the server refuse this with a 400
		// that the editor showed only as "Failed to save schedule".
		it('a chapter ID used twice is named before anything is sent', async () => {
			api.schedule = {
				schedule: [
					{ chapter_ref: 'chapter-03', chapter_title: 'Switching', start_date: null, target_date: null, sort_order: 1 },
					{ chapter_ref: 'chapter-03', chapter_title: 'Switching II', start_date: null, target_date: null, sort_order: 2 },
				],
				chapters_in_scope: [],
				known_chapter_refs: ['chapter-03'],
			}
			const tab = await mountTab({ activeTab: 'schedule' })
			const emitted = []
			tab.$.emit = (name, ...args) => emitted.push([name, ...args])

			buttonByText('Zeitplan speichern').click()
			await flush()

			expect(axios.put.mock.calls.some(([url]) => url === '/apps/learning/api/courses/5/schedule')).toBe(false)
			expect(emitted).toEqual([['error', 'Die Kapitel-Kennung „chapter-03“ kommt mehrfach vor. Jede Zeile braucht eine eigene Kennung.']])
		})

		it('two rows without an ID but with the same title collide too, and are named', async () => {
			api.schedule = {
				schedule: [
					{ chapter_ref: '', chapter_title: 'Basics', start_date: null, target_date: null, sort_order: 1 },
					{ chapter_ref: '', chapter_title: 'Basics', start_date: null, target_date: null, sort_order: 2 },
				],
				chapters_in_scope: [],
				known_chapter_refs: [],
			}
			const tab = await mountTab({ activeTab: 'schedule' })
			const emitted = []
			tab.$.emit = (name, ...args) => emitted.push([name, ...args])

			buttonByText('Zeitplan speichern').click()
			await flush()

			expect(axios.put.mock.calls.some(([url]) => url === '/apps/learning/api/courses/5/schedule')).toBe(false)
			expect(emitted[0][1]).toContain('„basics“')
		})

		it('a refused save names the server reason instead of only "Failed to save schedule"', async () => {
			api.schedule = {
				schedule: [{ chapter_ref: 'chapter-01', chapter_title: 'Basics', start_date: null, target_date: null, sort_order: 1 }],
				chapters_in_scope: [],
				known_chapter_refs: [],
			}
			const tab = await mountTab({ activeTab: 'schedule' })
			const emitted = []
			tab.$.emit = (name, ...args) => emitted.push([name, ...args])
			axios.put.mockImplementationOnce(() => Promise.reject({ response: { status: 400, data: { error: 'Entry 0: chapter_ref too long' } } }))

			buttonByText('Zeitplan speichern').click()
			await flush()

			expect(emitted).toEqual([['error', 'Failed to save schedule: Entry 0: chapter_ref too long']])
		})

		// Pre-existing: a failed GET left an empty editor that looked like "no schedule yet". Adding a
		// row and saving then replaced the whole stored schedule (setSchedule deletes before inserting).
		it('a failed load shows an error with retry and offers no editor that could overwrite the schedule', async () => {
			api.schedule = Object.assign(new Error('boom'), { response: { status: 500 } })
			const tab = await mountTab({ activeTab: 'schedule' })

			expect(host.querySelector('[data-test="schedule-load-error"]')).toBeTruthy()
			expect(Array.from(host.querySelectorAll('button')).some((b) => b.textContent.trim() === '+ Kapitel hinzufuegen')).toBe(false)
			expect(Array.from(host.querySelectorAll('button')).some((b) => b.textContent.trim() === 'Zeitplan speichern')).toBe(false)

			// Even a programmatic save is refused while the stored schedule is unknown.
			tab.scheduleItems = [{ chapter_ref: 'x', chapter_title: 'X', start_date: null, target_date: null }]
			await tab.saveSchedule()
			expect(axios.put.mock.calls.some(([url]) => url === '/apps/learning/api/courses/5/schedule')).toBe(false)

			api.schedule = {
				schedule: [{ chapter_ref: 'chapter-01', chapter_title: 'Basics', start_date: null, target_date: null, sort_order: 1 }],
				chapters_in_scope: [],
				known_chapter_refs: [],
			}
			buttonByText('Erneut versuchen').click()
			await flush()
			expect(host.querySelector('[data-test="schedule-load-error"]')).toBeNull()
			expect(host.querySelector('[data-test="schedule-chapter-ref"]').value).toBe('chapter-01')
		})

		it('free text stays allowed: a typed chapter_ref outside the suggestions is sent as typed', async () => {
			api.schedule = { schedule: [], chapters_in_scope: [], known_chapter_refs: ['chapter-01'] }
			await mountTab({ activeTab: 'schedule' })

			buttonByText('+ Kapitel hinzufuegen').click()
			await flush()
			const refInput = host.querySelector('[data-test="schedule-chapter-ref"]')
			refInput.value = 'Wiederholung'
			refInput.dispatchEvent(new Event('input'))
			await flush()
			buttonByText('Zeitplan speichern').click()
			await flush()

			const call = axios.put.mock.calls.find(([url]) => url === '/apps/learning/api/courses/5/schedule')
			expect(call[1].entries[0].chapter_ref).toBe('Wiederholung')
		})
	})
})
