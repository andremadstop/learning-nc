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
			strength: t('learning', 'Troubleshooting, Hardware, Support'),
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
			t('learning', 'Montagmorgen: nichts geht. Alle Systeme von NovaTech sind down. Du bist das Notfall-Team.'),
			'intermediate', ['network_fundamentals'], 60, { is_graph: true }),
		campaign('einbruch_im_netz', '🔐', t('learning', 'Einbruch im Netz'),
			t('learning', 'Dienstag, 03:47 Uhr. 847 fehlgeschlagene Logins in 12 Minuten. Das ist kein normaler Dienstag.'),
			'advanced', ['security_fundamentals'], 60),
		campaign('der_neue_standort', '🏢', t('learning', 'Der neue Standort'),
			t('learning', 'NovaTech expandiert. Neues Büro, 40 Arbeitsplätze, 4 Wochen Deadline. Kein Fehler erlaubt.'),
			'intermediate', ['network_fundamentals', 'security_fundamentals'], 75),
		campaign('ransomware', '💀', t('learning', 'Ransomware'),
			t('learning', 'Freitag 16:30 Uhr: „YOUR FILES HAVE BEEN ENCRYPTED.“ 48 Stunden bis zur Deadline.'),
			'advanced', ['security_fundamentals'], 60),
		campaign('das_erbe', '💾', t('learning', 'Das Erbe'),
			t('learning', 'NovaTech übernimmt Oldstyle GmbH. Windows Server 2012, ein Hub (kein Switch!) und admin123.'),
			'beginner', ['it_fundamentals', 'network_fundamentals', 'linux_fundamentals'], 75),
	]
}

/**
 * The focus tags of a campaign (`focus_areas`) as the reader sees them. The campaign files carry
 * ids such as `network_fundamentals` or `incident-response` (both separators occur); the Adventure
 * cards showed those raw. Every id the shipped campaigns use has a label here; the map is built on
 * call, so the translations are registered by then. The raw ids stay in the files and in the story
 * prompts.
 *
 * @return {Object<string, string>} normalised id (lower case, "_") → label
 */
function focusAreaLabels() {
	return {
		security_fundamentals: t('learning', 'Sicherheitsgrundlagen'),
		threat_analysis: t('learning', 'Bedrohungsanalyse'),
		network_fundamentals: t('learning', 'Netzwerkgrundlagen'),
		it_fundamentals: t('learning', 'IT-Grundlagen'),
		linux_fundamentals: t('learning', 'Linux-Grundlagen'),
		firewall: t('learning', 'Firewall'),
		dns: t('learning', 'DNS'),
		routing: t('learning', 'Routing'),
		// named after the skill, not after the tool the campaign uses
		wireshark: t('learning', 'Paketanalyse'),
		incident_response: t('learning', 'Vorfallbehandlung'),
		troubleshooting: t('learning', 'Fehlersuche'),
		security: t('learning', 'Sicherheit'),
		forensics: t('learning', 'Forensik'),
		phishing: t('learning', 'Phishing'),
		port_scanning: t('learning', 'Portscans'),
		credential_management: t('learning', 'Zugangsdatenverwaltung'),
		vulnerability_management: t('learning', 'Schwachstellenmanagement'),
		network_segmentation: t('learning', 'Netzsegmentierung'),
		waf: t('learning', 'Web Application Firewall (WAF)'),
		access_control: t('learning', 'Zugriffskontrolle'),
		patch_management: t('learning', 'Patch-Management'),
		secure_architecture: t('learning', 'Sichere Architektur'),
	}
}

/**
 * @param {string} tag a focus id as in the campaign file
 * @return {string} lower case, "-" and spaces as "_"
 */
export function normalizeFocusArea(tag) {
	return String(tag ?? '').trim().toLowerCase().replace(/[-\s]+/g, '_')
}

/** @return {string[]} the normalised focus ids that have a label */
export function knownFocusAreas() {
	return Object.keys(focusAreaLabels())
}

/**
 * A focus tag for display. An id without a label (a campaign added later) is never shown raw:
 * separators become spaces and the first letter is capitalised ("cloud_security" → "Cloud security").
 *
 * @param {string} tag a focus id as in the campaign file
 * @return {string}
 */
export function focusAreaLabel(tag) {
	const key = normalizeFocusArea(tag)
	const label = focusAreaLabels()[key]
	if (label) return label
	const words = key.replace(/_+/g, ' ').trim()
	return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}
