/**
 * GitHub #42: in the compliance report an instructor could only revoke a certificate. Organisations
 * must keep copies of their staff's certificates for audits, so each row now offers View (the
 * learner's certificate, whose Print gives the PDF) and Download (the learner's file), and the
 * filtered list can be downloaded as one ZIP. All three go through the course-scoped instructor
 * endpoints; Certificate.vue in that mode labels revoked / expired certificates.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import axios from '@nextcloud/axios'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
// The real @nextcloud/router fills {placeholders}; this stand-in does the same so the URLs are checked.
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => url.replace(/{(\w+)}/g, (_m, k) => encodeURIComponent(String(params[k])))),
}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'teacher' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', inheritAttrs: true, template: '<button><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div class="nc-modal-stub"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
vi.mock('../../src/components/StudentDetail.vue', () => stub('StudentDetail'))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))
vi.mock('../../src/components/Klassenbuch.vue', () => stub('Klassenbuch'))
vi.mock('../../src/components/CourseMemberPicker.vue', () => stub('CourseMemberPicker'))

const VID = '11111111-1111-4111-8111-111111111111'
const ROWS = [
	{ display_name: 'Jürgen Müller', passed_at: 1700000000, score: '90', expires_at: null, verification_id: VID, expired: false },
]

globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

function makeJwt(payload) {
	const bytes = new TextEncoder().encode(JSON.stringify(payload))
	let binary = ''
	bytes.forEach((b) => { binary += String.fromCharCode(b) })
	return `aGVhZGVy.${btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')}.c2ln`
}
const CREDENTIAL = {
	issuer: { name: 'Akademie Nord' },
	validFrom: '2026-06-27T10:00:00+00:00',
	credentialSubject: { name: 'Jürgen Müller', achievement: { name: 'Compliance' }, result: [{ resultDescription: 'score:90; threshold:80' }] },
}

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

function mount(component, props) {
	const host = document.createElement('div')
	document.body.appendChild(host)
	const app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = (_a, s, p, c) => (c === 1 ? s : p)
	app.config.warnHandler = () => {}
	app.mount(host)
	return { host, unmount: () => { app.unmount(); host.remove() } }
}

function buttonByText(host, text) {
	return Array.from(host.querySelectorAll('button')).find((b) => b.textContent.trim() === text)
}

describe('compliance report: certificate copies for the instructor (GitHub #42)', () => {
	let CourseTabTeilnehmer
	let originalLocation
	let view

	beforeEach(async () => {
		CourseTabTeilnehmer = (await import('../../src/components/CourseTabTeilnehmer.vue')).default
		axios.get.mockReset()
		axios.get.mockImplementation((url) => {
			if (url.includes('/cert-report')) {
				return Promise.resolve({ data: { rows: ROWS, signing_available: true, passed_without_certificate: [] } })
			}
			if (url.includes(`/courses/5/certificates/${VID}`)) {
				return Promise.resolve({ data: { verification_id: VID, credential_json: makeJwt(CREDENTIAL), revoked: false, expired: false } })
			}
			return Promise.resolve({ data: [] })
		})
		originalLocation = window.location
		delete window.location
		window.location = { href: '', origin: 'https://cloud.example' }
		view = mount(CourseTabTeilnehmer, {
			courseId: 5,
			course: { is_instructor: true, cert_enabled: true, mode_config: { course_summary: false } },
			userRole: 'instructor',
			actsAsInstructor: true,
			activeTab: 'summary',
		})
		await flush()
	})

	afterEach(() => {
		view.unmount()
		window.location = originalLocation
	})

	it('offers View and Download next to Revoke on every row, with a name for screen readers', () => {
		const row = view.host.querySelector('.cert-report-table tbody tr')
		const labels = Array.from(row.querySelectorAll('button')).map((b) => b.textContent.trim())
		expect(labels).toEqual(['Ansehen', 'Herunterladen (JSON)', 'Widerrufen'])
		expect(row.querySelector('.cert-view').getAttribute('aria-label')).toBe('Zertifikat von Jürgen Müller ansehen')
		expect(row.querySelector('.cert-download').getAttribute('aria-label')).toBe('Herunterladen (JSON): signierte Zertifikatsdatei von Jürgen Müller')
	})

	it('says what the copies are: the downloads are the signed JSON files, the PDF is View -> Print', () => {
		expect(view.host.querySelector('.cert-report-zip').textContent.trim()).toBe('Alle als JSON herunterladen (ZIP)')
		const hint = view.host.querySelector('.cert-report-copies-hint')
		expect(hint).not.toBeNull()
		expect(hint.textContent).toContain('PDF')
		expect(hint.textContent).toContain('JSON')
	})

	it('Download fetches the learner file through the course-scoped instructor endpoint', async () => {
		view.host.querySelector('.cert-download').click()
		expect(window.location.href).toBe(`/apps/learning/api/courses/5/certificates/${VID}/download`)
	})

	function reportQueries() {
		return axios.get.mock.calls.map(([url]) => url).filter((url) => /\/cert-report(\?|$)/.test(url))
	}

	it('Download all (ZIP) packs the list the table shows: the applied filter, not unapplied edits', async () => {
		const input = view.host.querySelector('.cert-report-filters input[type="number"]')
		input.value = '30'
		input.dispatchEvent(new Event('input'))
		buttonByText(view.host, 'Filter anwenden').click()
		await flush()
		const applied = reportQueries().at(-1).split('?')[1]
		expect(applied).toContain('expiringDays=30')

		// Edited but not applied: the table still shows the 30-day list, so the ZIP must too.
		input.value = '5'
		input.dispatchEvent(new Event('input'))
		await nextTick()

		buttonByText(view.host, 'Alle als JSON herunterladen (ZIP)').click()
		expect(window.location.href).toBe('/apps/learning/api/courses/5/cert-report/export/zip?' + applied)
	})

	// GitHub #45: the CSV went out with the filter inputs as they were typed, so after an edit
	// without "Filter anwenden" it held a different set than the table. It follows the table now.
	it('Export CSV exports the list the table shows: the applied filter, not unapplied edits', async () => {
		const input = view.host.querySelector('.cert-report-filters input[type="number"]')
		input.value = '30'
		input.dispatchEvent(new Event('input'))
		buttonByText(view.host, 'Filter anwenden').click()
		await flush()
		const applied = reportQueries().at(-1).split('?')[1]
		expect(applied).toContain('expiringDays=30')

		input.value = '5'
		input.dispatchEvent(new Event('input'))
		await nextTick()

		buttonByText(view.host, 'Ausgestellte Zertifikate als CSV').click()
		expect(window.location.href).toBe('/apps/learning/api/courses/5/cert-report/export/csv?' + applied)
	})

	it('Export CSV without a filter carries no query string', () => {
		buttonByText(view.host, 'Ausgestellte Zertifikate als CSV').click()
		expect(window.location.href).toBe('/apps/learning/api/courses/5/cert-report/export/csv')
	})

	it('View opens the learner certificate through the instructor endpoint, without the LinkedIn action', async () => {
		expect(view.host.querySelector('.cert-view-modal')).toBeNull()
		view.host.querySelector('.cert-view').click()
		await flush()

		const modal = view.host.querySelector('.cert-view-modal')
		expect(modal).not.toBeNull()
		expect(axios.get).toHaveBeenCalledWith(`/apps/learning/api/courses/5/certificates/${VID}`)
		expect(axios.get).not.toHaveBeenCalledWith(`/apps/learning/api/certificates/${VID}`)
		expect(modal.querySelector('.certificate-title').textContent).toContain('Compliance')
		expect(buttonByText(modal, 'Drucken')).toBeTruthy()
		// The instructor copy names the file type (the learner view below does too). GitHub #95: behind
		// "Technische Details", always shown in the instructor copy.
		expect(modal.querySelector('details.certificate-credential')).not.toBeNull()
		expect(buttonByText(modal, 'Nachweis herunterladen (JSON)')).toBeTruthy()
		expect(modal.textContent).not.toContain('LinkedIn')
	})
})

describe('Certificate.vue in instructor mode labels a certificate that no longer counts (GitHub #42)', () => {
	let Certificate

	beforeEach(async () => {
		Certificate = (await import('../../src/components/Certificate.vue')).default
		axios.get.mockReset()
	})

	async function note(row, props) {
		axios.get.mockResolvedValue({ data: { verification_id: VID, credential_json: makeJwt(CREDENTIAL), ...row } })
		const view = mount(Certificate, { verificationId: VID, ...props })
		await flush()
		try {
			return view.host.querySelector('.certificate-status-note')?.textContent.trim() ?? null
		} finally {
			view.unmount()
		}
	}

	it('revoked', async () => {
		expect(await note({ revoked: true, expired: false }, { courseId: 5 }))
			.toBe('Dieses Zertifikat wurde widerrufen und ist nicht mehr gültig.')
	})

	it('expired', async () => {
		expect(await note({ revoked: false, expired: true }, { courseId: 5 })).toBe('Dieses Zertifikat ist abgelaufen.')
	})

	it('valid: no note', async () => {
		expect(await note({ revoked: false, expired: false }, { courseId: 5 })).toBeNull()
	})

	// GitHub #94 (ZB5): the learner's own card carries the note too, so its PDF never looks valid.
	it('learner view: own endpoint; a valid certificate has no note, a revoked one says so', async () => {
		expect(await note({ revoked: false }, {})).toBeNull()
		expect(axios.get).toHaveBeenCalledWith(`/apps/learning/api/certificates/${VID}`)
		expect(await note({ revoked: true }, {})).toBe('Dieses Zertifikat wurde widerrufen und ist nicht mehr gültig.')
	})

	// GitHub #67: the course can switch "Add to LinkedIn" off; a missing field keeps it.
	it('learner view hides LinkedIn when the course switched it off, and keeps it otherwise', async () => {
		for (const [row, shown] of [[{ linkedin_allowed: false }, false], [{ linkedin_allowed: true }, true], [{}, true]]) {
			axios.get.mockResolvedValue({ data: { verification_id: VID, credential_json: makeJwt(CREDENTIAL), ...row } })
			const view = mount(Certificate, { verificationId: VID })
			await flush()
			try {
				expect(view.host.textContent.includes('LinkedIn'), JSON.stringify(row)).toBe(shown)
				expect(buttonByText(view.host, 'Drucken')).toBeTruthy()
			} finally {
				view.unmount()
			}
		}
	})

	// Fresh-eyes C3 (#35): a plain "Download" made learners expect a PDF and get a JSON file.
	it('learner view names the file type too', async () => {
		axios.get.mockResolvedValue({ data: { verification_id: VID, credential_json: makeJwt(CREDENTIAL) } })
		const view = mount(Certificate, { verificationId: VID })
		await flush()
		try {
			expect(buttonByText(view.host, 'Nachweis herunterladen (JSON)')).toBeTruthy()
			expect(buttonByText(view.host, 'Herunterladen')).toBeUndefined()
		} finally {
			view.unmount()
		}
	})
})

// GitHub #95: the signed credential file sits behind a closed "Technische Details" disclosure with a
// name and one sentence; the course can hide it for learners, the instructor copy always shows it.
// Download route and file are unchanged.
describe('Certificate.vue: credential behind "Technische Details" (GitHub #95)', () => {
	let Certificate
	let originalLocation

	beforeEach(async () => {
		Certificate = (await import('../../src/components/Certificate.vue')).default
		axios.get.mockReset()
		originalLocation = window.location
		delete window.location
		window.location = { href: '', origin: 'https://cloud.example' }
	})

	afterEach(() => {
		window.location = originalLocation
	})

	async function render(row, props = {}) {
		axios.get.mockResolvedValue({ data: { verification_id: VID, credential_json: makeJwt(CREDENTIAL), ...row } })
		const view = mount(Certificate, { verificationId: VID, ...props })
		await flush()
		return view
	}

	it('learner, switch on: a closed disclosure with name, explanation and the old download', async () => {
		const view = await render({ credential_download_allowed: true })
		try {
			const details = view.host.querySelector('details.certificate-credential')
			expect(details).not.toBeNull()
			expect(details.open).toBe(false)
			expect(details.querySelector('summary').textContent.trim()).toBe('Technische Details')
			expect(details.textContent).toContain('Digitaler Nachweis für IT-Systeme (JSON)')
			expect(details.textContent).toContain('W3C Verifiable Credential')
			// the actions row keeps Print and loses the JSON button
			const actions = view.host.querySelector('.certificate-actions')
			expect(buttonByText(actions, 'Drucken')).toBeTruthy()
			expect(actions.textContent).not.toContain('JSON')
			// outside the printed card, and never printed
			expect(view.host.querySelector('.certificate-printable details')).toBeNull()
			expect(details.classList.contains('no-print')).toBe(true)

			const link = details.querySelector('a.certificate-download-link')
			expect(link.getAttribute('href')).toBe(`/apps/learning/api/certificates/${VID}/download`)
			expect(link.hasAttribute('download')).toBe(true)
			buttonByText(details, 'Nachweis herunterladen (JSON)').click()
			expect(window.location.href).toBe(`/apps/learning/api/certificates/${VID}/download`)
		} finally {
			view.unmount()
		}
	})

	it('learner, switch off: no disclosure and no JSON button, Print stays', async () => {
		const view = await render({ credential_download_allowed: false })
		try {
			expect(view.host.querySelector('details')).toBeNull()
			expect(view.host.textContent).not.toContain('JSON')
			expect(view.host.textContent).not.toContain('Technische Details')
			expect(buttonByText(view.host, 'Drucken')).toBeTruthy()
		} finally {
			view.unmount()
		}
	})

	it('learner, field missing (older server or failed read): the section is shown', async () => {
		const view = await render({})
		try {
			expect(view.host.querySelector('details.certificate-credential')).not.toBeNull()
		} finally {
			view.unmount()
		}
	})

	it('instructor copy shows it even when the course hides it for learners', async () => {
		const view = await render({ credential_download_allowed: false }, { courseId: 5 })
		try {
			const details = view.host.querySelector('details.certificate-credential')
			expect(details).not.toBeNull()
			expect(details.querySelector('a.certificate-download-link').getAttribute('href'))
				.toBe(`/apps/learning/api/courses/5/certificates/${VID}/download`)
		} finally {
			view.unmount()
		}
	})

	it('the LinkedIn switch and the credential switch are independent', async () => {
		const view = await render({ credential_download_allowed: false, linkedin_allowed: true })
		try {
			expect(view.host.textContent).toContain('LinkedIn')
			expect(view.host.querySelector('details')).toBeNull()
		} finally {
			view.unmount()
		}
	})
})

// Review of #42, WCAG 2.5.3 Label in Name: the accessible name of each certificate action must
// contain its visible text, in every language, so speech input ("click Download JSON") matches.
describe('certificate actions: the visible label is part of the accessible name (GitHub #42)', () => {
	const pairs = [
		['Ansehen', 'Zertifikat von {name} ansehen'],
		['Herunterladen (JSON)', 'Herunterladen (JSON): signierte Zertifikatsdatei von {name}'],
	]
	for (const lang of ['de', 'en', 'fr', 'ru', 'ar', 'uk']) {
		it(lang, async () => {
			const { readFileSync } = await import('node:fs')
			const { resolve } = await import('node:path')
			const catalogue = JSON.parse(readFileSync(resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations
			for (const [visible, aria] of pairs) {
				const shown = catalogue[visible]
				const name = catalogue[aria]
				expect(shown, `${lang}: ${visible}`).toBeTruthy()
				expect(name, `${lang}: ${aria}`).toBeTruthy()
				expect(name.toLocaleLowerCase(lang), `${lang}: "${name}" contains "${shown}"`).toContain(shown.toLocaleLowerCase(lang))
			}
		})
	}
})
