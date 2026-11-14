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
		expect(row.querySelector('.cert-download').getAttribute('aria-label')).toBe('Signierte Zertifikatsdatei (JSON) von Jürgen Müller herunterladen')
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
		// The instructor copy names the file type; the learner view below keeps the plain label.
		expect(buttonByText(modal, 'Herunterladen (JSON)')).toBeTruthy()
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

	it('learner view is unchanged: own endpoint, LinkedIn offered, no note', async () => {
		expect(await note({ revoked: true }, {})).toBeNull()
		expect(axios.get).toHaveBeenCalledWith(`/apps/learning/api/certificates/${VID}`)
	})

	it('learner view keeps the plain Download label', async () => {
		axios.get.mockResolvedValue({ data: { verification_id: VID, credential_json: makeJwt(CREDENTIAL) } })
		const view = mount(Certificate, { verificationId: VID })
		await flush()
		try {
			expect(buttonByText(view.host, 'Herunterladen')).toBeTruthy()
			expect(buttonByText(view.host, 'Herunterladen (JSON)')).toBeUndefined()
		} finally {
			view.unmount()
		}
	})
})
