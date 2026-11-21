import { translate as t } from '@nextcloud/l10n'

/**
 * Languages a question or answer translation — and so the personal content language — may have:
 * the app's six interface languages. The server's list is TranslationService::ALLOWED_LANGS
 * (tests/unit/contentLanguages.test.js compares the two). fr and uk were missing until 5.18.0,
 * in this list's six hard-coded copies (settings, app shell, translation dialog, pool languages).
 */
export const CONTENT_LANGUAGES = Object.freeze(['de', 'en', 'fr', 'ru', 'ar', 'uk'])

/** Languages written right to left (the translation dialog sets dir on their fields). */
const RTL = new Set(['ar'])

/** A content language code, or '' for anything else (original content). */
export function contentLanguageOrEmpty(lang) {
	return CONTENT_LANGUAGES.includes(lang) ? lang : ''
}

/**
 * The content languages with their label, in list order: [{ key, label, dir? }].
 * Labels go through t() at call time, so they follow the reader's interface language.
 */
export function contentLanguageOptions() {
	const labels = {
		de: t('learning', 'German (Deutsch)'),
		en: t('learning', 'English'),
		fr: t('learning', 'French'),
		ru: t('learning', 'Russian'),
		ar: t('learning', 'Arabic'),
		uk: t('learning', 'Ukrainian'),
	}
	return CONTENT_LANGUAGES.map((key) => (RTL.has(key) ? { key, label: labels[key], dir: 'rtl' } : { key, label: labels[key] }))
}
