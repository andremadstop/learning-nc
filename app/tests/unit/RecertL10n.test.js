import { describe, it, expect } from 'vitest'
import fs from 'fs'

/**
 * The recertification reminder (Notifier case 'recert_reminder') is sent through the symbolic keys
 * recert_reminder_subject / recert_reminder_body. A symbolic key has no readable fallback: if it is
 * missing in a language, the notification shows the key itself. This test checks both keys in all
 * six app languages, with the placeholders the Notifier fills.
 *
 * Audit #203: the file used to list five languages (no uk), carried a stale "RED now" header,
 * asserted the length of its own constant, and guarded eight more keys (cert_status_*,
 * recert_reenroll_*, recert_*_label) that no code uses. Those eight stay in the catalogues for
 * now; whether they go is part of the general cleanup of unused l10n keys
 * (audit-tools/l10n-unused-keys.txt), not of this test.
 */

function readText(relativePath) {
	return fs.readFileSync(new URL(relativePath, import.meta.url), 'utf8')
}

const LANG_NAMES = ['de', 'en', 'fr', 'ru', 'ar', 'uk']

// key -> placeholders the Notifier passes ($l->t(key, [...]))
const RECERT_KEYS = {
	recert_reminder_subject: ['%1$s', '%2$s'], // days left, course title
	recert_reminder_body: ['%1$s', '%2$s'], // course title, expiry date
}

const langs = Object.fromEntries(
	LANG_NAMES.map((lang) => {
		const raw = JSON.parse(readText(`../../l10n/${lang}.json`))
		return [lang, raw.translations ?? raw]
	}),
)

describe('Recert reminder l10n — all six languages', () => {
	it('the keys checked here are the ones the Notifier sends', () => {
		const notifier = readText('../../lib/Notification/Notifier.php')
		for (const key of Object.keys(RECERT_KEYS)) {
			expect(notifier).toContain(`$l->t('${key}'`)
		}
	})

	describe.each(LANG_NAMES)('lang "%s"', (lang) => {
		const dict = langs[lang]

		it.each(Object.entries(RECERT_KEYS))('has "%s" with its placeholders', (key, placeholders) => {
			expect(dict, `Missing "${key}" in ${lang}.json`).toHaveProperty(key)
			expect(dict[key], `"${key}" is untranslated in ${lang}.json`).not.toBe(key)
			for (const placeholder of placeholders) {
				expect(dict[key], `"${key}" in ${lang}.json lacks ${placeholder}`).toContain(placeholder)
			}
		})
	})
})
