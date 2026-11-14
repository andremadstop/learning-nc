import { translate as t } from '@nextcloud/l10n'
import privacyData from '../../data/privacy-info.json'
import consentText from '../../data/ai-consent.json'

const CATEGORY_GROUPS = [
	{
		id: 'learning',
		title: 'Lerndaten',
		matches: [/^learning$/i, /Lerndaten/i],
	},
	{
		id: 'ai',
		title: 'Persoenlichkeitsprofil / Telos & KI-Interaktionen',
		matches: [/^ai$/i, /Persoenlichkeitsprofil/i, /Telos/i, /KI-Interaktion/i],
	},
	{
		id: 'social',
		title: 'Schwarmgedaechtnis',
		matches: [/^social$/i, /Schwarm/i],
	},
	{
		id: 'audit',
		title: 'Audit & Moderation',
		matches: [/^audit$/i, /Audit/i],
	},
	{
		id: 'gamification',
		title: 'Gamification',
		matches: [/^gamification$/i, /Gamification/i],
	},
	{
		id: 'assessment',
		title: 'Prüfungsdaten',
		matches: [/^assessment$/i, /Prüf/i, /Snapshot/i],
	},
	{
		id: 'external',
		title: 'Externe Dienste',
		matches: [/^external$/i, /Extern/i, /Vault/i],
	},
]

function stringifyList(value) {
	if (Array.isArray(value)) {
		return value.join(', ')
	}
	return typeof value === 'string' ? value : ''
}

/** Puts the AI recipient into a text; the texts name nobody themselves (spec 3.6). */
function fillProvider(text, provider) {
	return typeof text === 'string' ? text.split('{provider}').join(provider) : ''
}

function providerLabel(data, provider) {
	return provider || data?.ai_notice?.provider_fallback || ''
}

function normalizeCategory(category, provider) {
	const fill = (text) => fillProvider(text, provider)
	return {
		id: category.id || '',
		name: category.name || category.title || '',
		description: fill(category.description || ''),
		items: fill(stringifyList(category.items || category.data_types)),
		purpose: fill(category.purpose || stringifyList(category.content)),
		who_sees: fill(category.who_sees || category.whoSees || ''),
		retention: fill(category.retention || ''),
		legal_basis: fill(category.legal_basis || category.legalBasis || ''),
	}
}

function matchesGroup(category, group) {
	return group.matches.some((pattern) => pattern.test(category.id) || pattern.test(category.name))
}

const RTL = ['ar', 'he', 'fa', 'ur']

/** "uk_UA" / "pt-BR" -> a language the consent catalog has, English otherwise (as the dialog does). */
function consentLanguage(locale) {
	const short = String(locale || '').toLowerCase().split(/[-_]/)[0]
	return consentText.locales[short] ? short : 'en'
}

function stripTags(html) {
	return typeof html === 'string' ? html.replace(/<[^>]+>/g, '').trim() : ''
}

function listItems(html) {
	return typeof html === 'string'
		? [...html.matchAll(/<li>(.*?)<\/li>/g)].map((m) => stripTags(m[1]))
		: []
}

/**
 * The AI notice as the page shows it, for the recipient in force, in the reader's language. The
 * text is the consent text (ai-consent.json, six languages, guarded by check-consent-locales.py):
 * the privacy page says what the dialog says, and nobody reads the disclosure in a language they
 * did not choose.
 *
 * @param {object} _data privacy-info.json (unused since the text comes from the consent catalog)
 * @param {{active: boolean, provider?: string, region?: string}|null} notice null while unknown:
 *   nothing is shown rather than a guess
 * @param {string} locale the reader's language (getLanguage())
 * @returns {object|null}
 */
export function buildAiNotice(_data = privacyData, notice = null, locale = 'de') {
	if (!notice || typeof notice !== 'object') {
		return null
	}
	const lang = consentLanguage(locale)
	const l = consentText.locales[lang]
	const dir = RTL.includes(lang) ? 'rtl' : 'ltr'
	if (!notice.active) {
		return { active: false, off: l.notice_off, lang, dir }
	}
	const provider = typeof notice.provider === 'string' ? notice.provider.trim() : ''
	if (provider === '') {
		// an active recipient without a name: show nothing rather than a guess
		return null
	}
	const fill = (text) => fillProvider(text, provider)
	const regions = l.region_paragraphs || {}
	return {
		active: true,
		lang,
		dir,
		provider,
		intro: stripTags(l.intro_html),
		providerSentence: fill(l.provider_sentence),
		dataHeading: l.data_list_heading,
		dataList: listItems(l.data_list_html),
		identity: l.identity_note,
		region: fill(regions[notice.region] || regions.unknown),
		automation: fill(l.automation_paragraph),
		retention: l.retention_note,
		optOut: l.revocation_note,
	}
}

export function buildPrivacyCategoryGroups(data = privacyData, provider = '') {
	const label = providerLabel(data, provider)
	const normalizedCategories = Array.isArray(data?.categories)
		? data.categories.map((category) => normalizeCategory(category, label))
		: []

	return CATEGORY_GROUPS.map((group) => ({
		id: group.id,
		title: group.title,
		entries: normalizedCategories.filter((category) => matchesGroup(category, group)),
	})).filter((group) => group.entries.length > 0)
}

/**
 * The data-subject rights the privacy page lists, in the reader's language. They used to be German
 * entries in privacy-info.json that the page rendered without t(), so every language showed them
 * in German (GitHub #38). The literal t() calls keep them visible to check-i18n-coverage.py.
 *
 * @return {Array<{id: string, name: string, article: string, description: string}>}
 */
export function buildRights() {
	return [
		{
			id: 'access',
			name: t('learning', 'Auskunft'),
			article: t('learning', 'Art. {n} DSGVO', { n: 15 }),
			description: t('learning', 'Du kannst jederzeit erfahren, welche Daten über dich gespeichert sind.'),
		},
		{
			id: 'rectification',
			name: t('learning', 'Berichtigung'),
			article: t('learning', 'Art. {n} DSGVO', { n: 16 }),
			description: t('learning', 'Unrichtige Daten können korrigiert werden.'),
		},
		{
			id: 'erasure',
			name: t('learning', 'Löschung'),
			article: t('learning', 'Art. {n} DSGVO', { n: 17 }),
			description: t('learning', 'Du kannst die Löschung deiner Daten verlangen.'),
		},
		{
			id: 'restriction',
			name: t('learning', 'Einschränkung'),
			article: t('learning', 'Art. {n} DSGVO', { n: 18 }),
			description: t('learning', 'Du kannst die Verarbeitung einschränken lassen.'),
		},
		{
			id: 'objection',
			name: t('learning', 'Widerspruch'),
			article: t('learning', 'Art. {n} DSGVO', { n: 21 }),
			description: t('learning', 'Du kannst der Verarbeitung widersprechen.'),
		},
		{
			id: 'portability',
			name: t('learning', 'Datenportabilität'),
			article: t('learning', 'Art. {n} DSGVO', { n: 20 }),
			description: t('learning', 'Du kannst deine Daten in einem gängigen Format erhalten.'),
		},
	]
}

export function getPrivacyInfoData() {
	return privacyData
}
