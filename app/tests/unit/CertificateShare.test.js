import { describe, expect, it } from 'vitest'
import {
	decodeCredential,
	extractCertificateFields,
	parseResult,
	buildVerifyUrl,
	buildLinkedInUrl,
} from '../../src/utils/certificate-credential.js'

/**
 * Phase 155 Plan 06 — pure helpers behind Certificate.vue.
 *
 * The project has no component-mounting harness (no @vue/test-utils; vitest collects
 * tests/unit/**\/*.test.js only), so the certificate logic lives in a util module and is
 * asserted here. The remaining two plan behaviors — window.print on a print trigger and
 * live i18n chrome rendering — are covered by the relay human-verify (steps 4 + 8).
 */

// Build a compact VC-JWT whose payload is UTF-8-safe base64url (mirrors the signer).
function makeJwt(payload) {
	const bytes = new TextEncoder().encode(JSON.stringify(payload))
	let binary = ''
	bytes.forEach((b) => { binary += String.fromCharCode(b) })
	const b64url = btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
	return `aGVhZGVy.${b64url}.c2lnbmF0dXJl`
}

// A representative OB3 credential matching the 155-04 issuance shape.
const credential = {
	'@context': ['https://www.w3.org/ns/credentials/v2'],
	id: 'urn:uuid:abc-123',
	type: ['VerifiableCredential', 'OpenBadgeCredential'],
	issuer: {
		id: 'did:web:devcloud.andrestiebitz.de:apps:learning',
		name: 'DevCloud',
		image: { id: 'https://devcloud.andrestiebitz.de/logo.png', type: 'Image' },
	},
	validFrom: '2026-06-27T10:00:00+00:00',
	validUntil: '2027-06-27T10:00:00+00:00',
	credentialSubject: {
		type: ['AchievementSubject'],
		name: 'Jürgen Müller',
		achievement: {
			name: 'Sicherheit & Compliance für Pflegekräfte',
			description: 'Grundlagen',
			criteria: { narrative: 'Passed with score >= 80% (lucky guesses excluded).' },
		},
		result: [{ type: ['Result'], resultDescription: 'score:88; threshold:80' }],
	},
}

describe('decodeCredential', () => {
	it('decodes a UTF-8 payload without mojibake (German umlauts, frozen field)', () => {
		const decoded = decodeCredential(makeJwt(credential))
		expect(decoded.credentialSubject.name).toBe('Jürgen Müller')
		expect(decoded.credentialSubject.achievement.name).toBe('Sicherheit & Compliance für Pflegekräfte')
	})

	it('returns null for a malformed (non-3-segment) token', () => {
		expect(decodeCredential('not.a.jwt.token')).toBeNull()
		expect(decodeCredential('only-one-segment')).toBeNull()
		expect(decodeCredential('')).toBeNull()
		expect(decodeCredential(null)).toBeNull()
	})
})

describe('parseResult', () => {
	it('extracts score and threshold from the resultDescription string', () => {
		expect(parseResult('score:88; threshold:80')).toEqual({ score: 88, threshold: 80 })
	})

	it('tolerates a missing score', () => {
		expect(parseResult('score:; threshold:70')).toEqual({ score: null, threshold: 70 })
	})
})

describe('extractCertificateFields', () => {
	it('flattens the frozen OB3 credential into display fields', () => {
		const f = extractCertificateFields(decodeCredential(makeJwt(credential)))
		expect(f.courseTitle).toBe('Sicherheit & Compliance für Pflegekräfte')
		expect(f.recipientName).toBe('Jürgen Müller')
		expect(f.issuerName).toBe('DevCloud')
		expect(f.issuerLogo).toBe('https://devcloud.andrestiebitz.de/logo.png')
		expect(f.score).toBe(88)
		expect(f.threshold).toBe(80)
		expect(f.validFrom).toBe('2026-06-27T10:00:00+00:00')
		expect(f.validUntil).toBe('2027-06-27T10:00:00+00:00')
	})

	it('does not re-translate frozen fields regardless of caller locale (i18n freeze)', () => {
		// extract is locale-agnostic — the signed course title is returned verbatim.
		const f = extractCertificateFields(decodeCredential(makeJwt(credential)))
		expect(f.courseTitle).toBe(credential.credentialSubject.achievement.name)
	})
})

describe('buildVerifyUrl', () => {
	it('builds the public verify URL containing the verification id', () => {
		const url = buildVerifyUrl('https://devcloud.andrestiebitz.de', 'abc-123')
		expect(url).toBe('https://devcloud.andrestiebitz.de/apps/learning/verify/abc-123')
		expect(url).toContain('verify/abc-123')
	})

	it('trims a trailing slash on the base URL', () => {
		expect(buildVerifyUrl('https://x.de/', 'v1')).toBe('https://x.de/apps/learning/verify/v1')
	})
})

describe('buildLinkedInUrl', () => {
	it('produces a well-formed profile/add URL with name + certId params', () => {
		const certUrl = 'https://devcloud.andrestiebitz.de/apps/learning/verify/abc-123'
		const url = buildLinkedInUrl({
			courseTitle: 'Sicherheit & Compliance für Pflegekräfte',
			issuerName: 'DevCloud',
			verificationId: 'abc-123',
			certUrl,
			issueDate: '2026-06-27T10:00:00+00:00',
		})
		expect(url.startsWith('https://www.linkedin.com/profile/add?')).toBe(true)
		const params = new URLSearchParams(url.split('?')[1])
		expect(params.get('startTask')).toBe('CERTIFICATION_NAME')
		expect(params.get('name')).toBe('Sicherheit & Compliance für Pflegekräfte')
		expect(params.get('organizationName')).toBe('DevCloud')
		expect(params.get('certId')).toBe('abc-123')
		expect(params.get('certUrl')).toBe(certUrl)
		expect(params.get('issueYear')).toBe('2026')
		expect(params.get('issueMonth')).toBe('6')
	})

	it('omits issue year/month when no date is given', () => {
		const params = new URLSearchParams(
			buildLinkedInUrl({ courseTitle: 'X', issuerName: 'Y', verificationId: 'z', certUrl: 'u' }).split('?')[1],
		)
		expect(params.has('issueYear')).toBe(false)
		expect(params.has('issueMonth')).toBe(false)
	})
})
