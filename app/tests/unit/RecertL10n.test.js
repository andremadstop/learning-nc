import { describe, it, expect } from 'vitest'
import fs from 'fs'

/**
 * Parity gate for v5.2.0 recertification lifecycle i18n keys (DSGVO-05 / RECERT-01/02/04/05/06).
 *
 * This is the canonical key list for Phase 164. ALL keys listed in RECERT_KEYS must exist
 * in ALL five languages before this test goes GREEN. 164-07 adds the keys; this test enforces
 * the parity gate runs automatically in Gate 1 (Vitest is in the pre-push hook).
 *
 * Pattern: mirrors app/tests/unit/BadgeL10n.test.js, extended to all 5 langs (de/en/fr/ru/ar).
 *
 * RED now: none of these keys exist in any l10n file yet.
 * GREEN trigger (164-07): all keys added to de/en/fr/ru/ar .json files + l10n_js_sync.py run.
 *
 * Pitfall reminder (RESEARCH §Pitfall 5): check-i18n-parity.sh is NOT in the pre-push hook;
 * this Vitest file fills that gap for the recert keys specifically.
 */

function readJson(relativePath) {
	return JSON.parse(
		fs.readFileSync(new URL(relativePath, import.meta.url), 'utf8'),
	)
}

// ─── Canonical new-key list (single source of truth for 164-07) ──────────────
// Grouped by feature area to make additions obvious.
const RECERT_KEYS = [
	// Reminder notification — Notifier case 'recert_reminder' (RECERT-06)
	'recert_reminder_subject',   // e.g. "Ihr Zertifikat läuft in {days} Tagen ab"
	'recert_reminder_body',      // body text with course + expiry date placeholder

	// Cert lifecycle status labels (RECERT-01/03, used in CertificateVerifyService)
	'cert_status_valid',         // Green state: cert within validity period
	'cert_status_expiring',      // Yellow state: within T-30/T-7 reminder window
	'cert_status_overdue',       // Orange state: past expiry, within RECERT_GRACE_DAYS
	'cert_status_expired',       // Red state: past grace period

	// Re-enrollment UI (RECERT-04/05, shown on course page after period-close)
	'recert_reenroll_cta',       // Call-to-action button: "Erneut belegen / Re-enroll"
	'recert_reenroll_info',      // Info text explaining re-certification is required

	// Admin config UI labels (RECERT-02 validity months, DSGVO-03 retention years)
	'recert_validity_months_label',  // Label for learning_courses.cert_validity_months setting
	'recert_retention_years_label',  // Label for retention_years IConfig app value
]

// ─── Load all 5 language files ───────────────────────────────────────────────
const LANG_NAMES = ['de', 'en', 'fr', 'ru', 'ar']

const langs = Object.fromEntries(
	LANG_NAMES.map((lang) => {
		const raw = readJson(`../../l10n/${lang}.json`)
		return [lang, raw.translations ?? raw]
	}),
)

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('Recert l10n completeness — 5-lang parity (v5.2.0)', () => {
	it('covers the canonical set of 10 new recert keys', () => {
		expect(RECERT_KEYS).toHaveLength(10)
	})

	describe.each(LANG_NAMES)('lang "%s"', (lang) => {
		const dict = langs[lang]

		it.each(RECERT_KEYS)('has key "%s"', (key) => {
			expect(dict, `Missing "${key}" in ${lang}.json`).toHaveProperty(key)
			expect(dict[key], `"${key}" is empty in ${lang}.json`).not.toBe('')
		})
	})
})
