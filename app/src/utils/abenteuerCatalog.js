import { translate as t } from '@nextcloud/l10n'

/**
 * GitHub #52: the four player characters and the fallback campaign list of the Adventure mode,
 * in the reader's language. Both were German constants in AbenteuerMode.vue. Built on call, not at
 * module load, so the translations are registered by then. Character names, icons, focus tags and
 * ids stay as they are; the campaign stories themselves come from German-only campaign files.
 *
 * @return {object[]}
 */
export function buildAdventureCharacters() {
	return [
		{
			id: 'architect',
			portrait: '👨‍💻',
			name: 'Alex "The Architect" Neumann',
			role: t('learning', 'Netzwerk-Architekt'),
			description: t('learning', 'Methodisch, analytisch. Denkt in OSI-Schichten. Stark bei Routing und Topologie-Design.'),
			strength: t('learning', 'Routing, VLANs, Subnetting'),
			weakness: t('learning', 'Security-Fragen dauern länger'),
		},
		{
			id: 'security',
			portrait: '👩‍💻',
			name: 'Sarah "Firewall" Okonkwo',
			role: t('learning', 'Security-Analystin'),
			description: t('learning', 'Paranoid im besten Sinne. Sieht überall Bedrohungen. Hat für jedes Szenario einen IR-Plan.'),
			strength: t('learning', 'Firewalls, Incident Response, Threats'),
			weakness: t('learning', 'Routing-Fragen brauchen mehr Zeit'),
		},
		{
			id: 'sysadmin',
			portrait: '🧔',
			name: 'Kai "Root" Lindström',
			role: t('learning', 'Sysadmin'),
			description: t('learning', 'Unix-Philosophie pur. Löst alles per Terminal. Hat für jedes Problem ein Bash-Skript.'),
			strength: t('learning', 'Linux, Scripting, DNS, Server'),
			weakness: t('learning', 'Wireless ist „Teufelszeug“'),
		},
		{
			id: 'helpdesk',
			portrait: '👩‍🦺',
			name: 'Mia "Helpdesk" Torres',
			role: t('learning', 'First-Level-Support'),
			description: t('learning', 'Troubleshooting-Talent. Kann Fachjargon in Alltagssprache übersetzen. Kennt das Drucker-Passwort.'),
			strength: t('learning', 'Troubleshooting, Hardware, A+'),
			weakness: t('learning', 'Tiefes Netzwerk-Wissen fehlt manchmal'),
		},
	]
}

/**
 * The campaign list the Adventure mode falls back to when the server list is empty or fails.
 *
 * @return {object[]}
 */
export function buildStaticCampaigns() {
	const campaign = (id, icon, title, description, difficulty, focusAreas, minutes, extra = {}) => ({
		id,
		icon,
		title,
		description,
		difficulty,
		focus_areas: focusAreas,
		duration_minutes: minutes,
		progress: 'not_started',
		current_scene: null,
		...extra,
	})
	return [
		campaign('grosser_ausfall', '📡', t('learning', 'Der große Ausfall (klassisch)'),
			t('learning', 'Montag morgen: nichts geht. Alle Systeme von NovaTech sind down. Du bist das Notfall-Team.'),
			'intermediate', ['Network+'], 60, { is_graph: true }),
		campaign('einbruch_im_netz', '🔐', t('learning', 'Einbruch im Netz'),
			t('learning', 'Dienstag, 03:47 Uhr. 847 fehlgeschlagene Logins in 12 Minuten. Das ist kein normaler Dienstag.'),
			'advanced', ['Security+'], 60),
		campaign('der_neue_standort', '🏢', t('learning', 'Der neue Standort'),
			t('learning', 'NovaTech expandiert. Neues Büro, 40 Arbeitsplätze, 4 Wochen Deadline. Kein Fehler erlaubt.'),
			'intermediate', ['Network+', 'Security+'], 75),
		campaign('ransomware', '💀', t('learning', 'Ransomware'),
			t('learning', 'Freitag 16:30 Uhr: „YOUR FILES HAVE BEEN ENCRYPTED.“ 48 Stunden bis zur Deadline.'),
			'advanced', ['Security+'], 60),
		campaign('das_erbe', '💾', t('learning', 'Das Erbe'),
			t('learning', 'NovaTech übernimmt Oldstyle GmbH. Windows Server 2012, ein Hub (kein Switch!) und admin123.'),
			'beginner', ['A+', 'Network+', 'Linux+'], 75),
	]
}
