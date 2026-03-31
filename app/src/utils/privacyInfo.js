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
		matches: [/^assessment$/i, /Pruef/i, /Prüf/i, /Snapshot/i],
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

function normalizeCategory(category) {
	return {
		id: category.id || '',
		name: category.name || category.title || '',
		description: category.description || '',
		items: stringifyList(category.items || category.data_types),
		purpose: category.purpose || stringifyList(category.content),
		who_sees: category.who_sees || category.whoSees || '',
		retention: category.retention || '',
		legal_basis: category.legal_basis || category.legalBasis || '',
	}
}

function matchesGroup(category, group) {
	return group.matches.some((pattern) => pattern.test(category.id) || pattern.test(category.name))
}

export function buildPrivacyCategoryGroups(data = privacyData) {
	const normalizedCategories = Array.isArray(data?.categories)
		? data.categories.map(normalizeCategory)
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
