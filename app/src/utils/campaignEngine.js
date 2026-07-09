/**
 * Kampagnen-Daten + reine Helfer für AbenteuerMode.vue (Zero-Behavior-Change extrahiert).
 * `this`-frei; difficultyLabel nutzt translate (als t) für die Schwierigkeits-Labels.
 */
import { translate as t } from '@nextcloud/l10n'

/** Statische Fallback-Kampagnen (nur Graph-Modus), falls der Server keine liefert. */
export const STATIC_CAMPAIGNS = [
	{
		id: 'grosser_ausfall',
		icon: '📡',
		title: 'Der große Ausfall',
		description: 'Montag morgen: nichts geht. Alle Systeme von NovaTech sind down. Du bist das Notfall-Team.',
		difficulty: 'intermediate',
		focus_areas: ['Network+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
		is_graph: true,
	},
	{
		id: 'einbruch_im_netz',
		icon: '🔐',
		title: 'Einbruch im Netz',
		description: 'Dienstag, 03:47 Uhr. 847 fehlgeschlagene Logins in 12 Minuten. Das ist kein normaler Dienstag.',
		difficulty: 'advanced',
		focus_areas: ['Security+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'der_neue_standort',
		icon: '🏢',
		title: 'Der neue Standort',
		description: 'NovaTech expandiert. Neues Büro, 40 Arbeitsplätze, 4 Wochen Deadline. Kein Fehler erlaubt.',
		difficulty: 'intermediate',
		focus_areas: ['Network+', 'Security+'],
		duration_minutes: 75,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'ransomware',
		icon: '💀',
		title: 'Ransomware',
		description: 'Freitag 16:30 Uhr: "YOUR FILES HAVE BEEN ENCRYPTED." 48 Stunden bis zur Deadline.',
		difficulty: 'advanced',
		focus_areas: ['Security+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'das_erbe',
		icon: '💾',
		title: 'Das Erbe',
		description: 'NovaTech übernimmt Oldstyle GmbH. Windows Server 2012, ein Hub (kein Switch!) und admin123.',
		difficulty: 'beginner',
		focus_areas: ['A+', 'Network+', 'Linux+'],
		duration_minutes: 75,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'test_graph_campaign',
		icon: '🧪',
		title: 'Test Graph Kampagne',
		description: 'Interne Test-Kampagne für den Graph-Modus mit Simulator-Challenges.',
		difficulty: 'intermediate',
		focus_areas: ['Network+'],
		duration_minutes: 30,
		progress: 'not_started',
		current_scene: null,
		is_graph: true,
	},
]

/** IDs der hervorgehobenen ("featured") Kampagnen — bestimmt Sortier-Priorität. */
export const FEATURED_CAMPAIGN_IDS = [
	'der_grosse_ausfall',
	'phishing_friday',
	'zero_day_rechenzentrum',
	'ghostline_quest',
]

/**
 * Filtert auf Graph-Kampagnen, normalisiert IDs/Flags und sortiert featured-zuerst,
 * dann alphabetisch nach Titel.
 */
export function normalizeCampaignList(rawCampaigns = []) {
	return (Array.isArray(rawCampaigns) ? rawCampaigns : [])
		.filter(campaign => campaign.is_graph || campaign.graph || campaign.graph_mode)
		.map((campaign) => {
			const id = campaign.id || campaign.campaign_id
			return {
				...campaign,
				id,
				is_graph: campaign.is_graph || campaign.graph_mode || false,
				is_featured: FEATURED_CAMPAIGN_IDS.includes(id),
			}
		})
		.sort((left, right) => {
			const featuredDelta = Number(right.is_featured) - Number(left.is_featured)
			if (featuredDelta !== 0) {
				return featuredDelta
			}
			return String(left.title || '').localeCompare(String(right.title || ''))
		})
}

/** Übersetztes Schwierigkeits-Label; unbekannte Werte werden unverändert zurückgegeben. */
export function difficultyLabel(d) {
	const map = {
		beginner: t('learning', 'Einsteiger'),
		intermediate: t('learning', 'Fortgeschritten'),
		advanced: t('learning', 'Experte'),
	}
	return map[d] || d
}
