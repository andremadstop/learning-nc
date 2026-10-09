import privacyData from '../../data/privacy-info.json'

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

/**
 * The AI notice as the page shows it, for the recipient in force.
 *
 * @param {object} data privacy-info.json
 * @param {{active: boolean, provider?: string, region?: string}|null} notice null while unknown:
 *   nothing is shown rather than a guess
 * @returns {object|null}
 */
export function buildAiNotice(data = privacyData, notice = null) {
	const n = data?.ai_notice
	if (!n || !notice || typeof notice !== 'object') {
		return null
	}
	if (!notice.active) {
		return { active: false, off: n.off }
	}
	const provider = providerLabel(data, notice.provider)
	const fill = (text) => fillProvider(text, provider)
	return {
		active: true,
		provider,
		intro: fill(n.intro),
		dataHeading: n.data_list_heading,
		dataList: Array.isArray(n.data_list) ? n.data_list : [],
		identity: n.identity_note,
		region: fill(n.region?.[notice.region] || n.region?.unknown),
		automation: fill(n.automation),
		retention: n.retention,
		optOut: n.opt_out,
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

export function getPrivacyInfoData() {
	return privacyData
}
