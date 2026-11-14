/**
 * Relative times and dates in the reader's language (audit #177, #183).
 *
 * CourseFeed and GlobalFeed each carried a German copy of this ('gerade eben', 'vor 3 Stunden',
 * a fixed TT.MM.JJJJ date), which no catalogue could translate. Intl knows the words and the
 * plural forms of all six app languages (ar has six, ru/uk three), so no catalogue keys are needed.
 */
import { getCanonicalLocale, getLanguage } from '@nextcloud/l10n'

/**
 * A locale Intl accepts. Nextcloud reports e.g. 'de', 'pt_BR' or 'de-DE'; an unknown or malformed
 * tag would throw a RangeError and take the whole feed down, so that falls back to English.
 *
 * @param {string|undefined} locale wanted locale
 * @return {string}
 */
export function intlLocale(locale) {
	const tag = String(locale || '').replace(/_/g, '-')
	try {
		return Intl.getCanonicalLocales(tag)[0] || 'en'
	} catch (e) {
		return 'en'
	}
}

/**
 * A date (no time) in the reader's locale.
 *
 * @param {number} timestamp unix seconds
 * @param {string} [locale] defaults to the Nextcloud locale
 * @return {string}
 */
export function formatLocalDate(timestamp, locale) {
	return new Date(timestamp * 1000).toLocaleDateString(intlLocale(locale || getCanonicalLocale()))
}

/**
 * "just now", "3 hours ago", "yesterday" … up to 30 days, after that the date.
 *
 * @param {number} timestamp unix seconds
 * @param {object} [options] options
 * @param {string} [options.locale] defaults to the Nextcloud user language
 * @param {number} [options.now] current time in ms (tests)
 * @return {string}
 */
export function formatRelativeTime(timestamp, { locale, now = Date.now() } = {}) {
	const lang = intlLocale(locale || getLanguage())
	const diff = Math.floor(now / 1000) - timestamp
	const rtf = new Intl.RelativeTimeFormat(lang, { numeric: 'auto' })

	if (diff < 60) {
		return rtf.format(0, 'second')
	}
	const minutes = Math.floor(diff / 60)
	if (minutes < 60) {
		return rtf.format(-minutes, 'minute')
	}
	const hours = Math.floor(diff / 3600)
	if (hours < 24) {
		return rtf.format(-hours, 'hour')
	}
	const days = Math.floor(diff / 86400)
	if (days <= 30) {
		return rtf.format(-days, 'day')
	}
	return new Date(timestamp * 1000).toLocaleDateString(lang)
}
