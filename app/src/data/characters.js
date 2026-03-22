/**
 * Character Registry — All 13 figures for Learning-NC campaign system.
 *
 * Each character has: id, name, role, personality, palette (primary/accent/glow
 * referencing --lnc-* design tokens), silhouette key, states, campaignAppearances.
 *
 * @module data/characters
 */

/**
 * Fallback character for unknown or missing IDs.
 */
const FALLBACK_CHARACTER = Object.freeze({
	id: 'unknown',
	name: '???',
	role: 'Unbekannt',
	personality: '',
	palette: {
		primary: 'var(--lnc-text-muted)',
		accent: 'var(--lnc-text-muted)',
		glow: 'var(--lnc-text-muted)',
	},
	silhouette: 'fallback',
	states: ['idle'],
	campaignAppearances: [],
})

/**
 * All 13 characters: 7 heroes + 6 workplace figures.
 */
const CHARACTERS = Object.freeze({

	// ── Heroes ──────────────────────────────────────────────────────────

	nova: Object.freeze({
		id: 'nova',
		name: 'NOVA',
		role: 'KI-Tutor / VirtuProf',
		personality: 'praezise, freundlich, leicht ironisch',
		palette: {
			primary: 'var(--lnc-primary)',
			accent: 'var(--lnc-cyan)',
			glow: 'var(--lnc-cyan)',
		},
		silhouette: 'nova',
		states: ['idle', 'thinking', 'explain', 'alert', 'celebrate'],
		campaignAppearances: ['*'],
	}),

	architect: Object.freeze({
		id: 'architect',
		name: 'ARCHITECT',
		role: 'Netzwerk-Architekt',
		personality: 'analytisch, ruhig, plant drei Schritte voraus',
		palette: {
			primary: 'var(--lnc-cyan)',
			accent: 'var(--lnc-primary)',
			glow: 'var(--lnc-amber)',
		},
		silhouette: 'architect',
		states: ['idle', 'thinking', 'alert', 'celebrate'],
		campaignAppearances: ['netzwerk_grundlagen', 'grosser_ausfall'],
	}),

	security: Object.freeze({
		id: 'security',
		name: 'SECURITY',
		role: 'Incident Responder',
		personality: 'wachsam, direkt, vertraut niemand leichtfertig',
		palette: {
			primary: 'var(--lnc-danger)',
			accent: 'var(--lnc-magenta)',
			glow: 'var(--lnc-cyan)',
		},
		silhouette: 'security',
		states: ['idle', 'thinking', 'alert', 'celebrate'],
		campaignAppearances: ['sicherheits_audit', 'ransomware_angriff'],
	}),

	sysadmin: Object.freeze({
		id: 'sysadmin',
		name: 'SYSADMIN',
		role: 'Linux-Veteran',
		personality: 'stoisch, kaffeesuechtig, hat alles schon gesehen',
		palette: {
			primary: 'var(--lnc-green)',
			accent: 'var(--lnc-ink)',
			glow: 'var(--lnc-amber)',
		},
		silhouette: 'sysadmin',
		states: ['idle', 'thinking', 'alert', 'celebrate'],
		campaignAppearances: ['server_migration', 'grosser_ausfall'],
	}),

	helpdesk: Object.freeze({
		id: 'helpdesk',
		name: 'HELPDESK',
		role: 'Troubleshooter',
		personality: 'geduldig, empathisch, loest Probleme durch Zuhoeren',
		palette: {
			primary: 'var(--lnc-primary)',
			accent: 'var(--lnc-amber)',
			glow: 'var(--lnc-green)',
		},
		silhouette: 'helpdesk',
		states: ['idle', 'thinking', 'alert', 'celebrate'],
		campaignAppearances: ['erste_woche', 'grosser_ausfall'],
	}),

	chronos: Object.freeze({
		id: 'chronos',
		name: 'CHRONOS',
		role: 'Zeitreise-Guide',
		personality: 'raetselhaft, philosophisch, spricht in Metaphern',
		palette: {
			primary: 'var(--lnc-ink)',
			accent: 'var(--lnc-amber)',
			glow: 'var(--lnc-cyan)',
		},
		silhouette: 'chronos',
		states: ['idle', 'thinking', 'alert', 'celebrate'],
		campaignAppearances: ['zeitreise_netzwerk'],
	}),

	ghostline: Object.freeze({
		id: 'ghostline',
		name: 'GHOSTLINE',
		role: 'Antagonist',
		personality: 'manipulativ, elegant, immer einen Schritt voraus',
		palette: {
			primary: 'var(--lnc-magenta)',
			accent: 'var(--lnc-danger)',
			glow: 'var(--lnc-ink)',
		},
		silhouette: 'ghostline',
		states: ['idle', 'thinking', 'alert', 'celebrate'],
		campaignAppearances: ['ransomware_angriff', 'sicherheits_audit'],
	}),

	// ── Workplace Figures ───────────────────────────────────────────────

	klaus_dau: Object.freeze({
		id: 'klaus_dau',
		name: 'Klaus DAU',
		role: 'Endanwender',
		personality: 'verwirrt aber gutmuetig, klickt alles kaputt',
		palette: {
			primary: 'var(--lnc-amber)',
			accent: 'var(--lnc-primary)',
			glow: 'var(--lnc-text-muted)',
		},
		silhouette: 'klaus_dau',
		states: ['idle', 'confused', 'frustrated', 'relieved', 'impressed'],
		campaignAppearances: ['erste_woche', 'grosser_ausfall'],
	}),

	dr_hartmann: Object.freeze({
		id: 'dr_hartmann',
		name: 'Dr. Hartmann',
		role: 'Geschaeftsfuehrer',
		personality: 'fokus Geld/KPIs, ungeduldig',
		palette: {
			primary: 'var(--lnc-ink)',
			accent: 'var(--lnc-primary)',
			glow: 'var(--lnc-amber)',
		},
		silhouette: 'dr_hartmann',
		states: ['idle', 'confused', 'frustrated', 'relieved', 'impressed'],
		campaignAppearances: ['erste_woche', 'grosser_ausfall'],
	}),

	frau_weber: Object.freeze({
		id: 'frau_weber',
		name: 'Frau Weber',
		role: 'Datenschutzbeauftragte',
		personality: 'blockiert aber hat recht',
		palette: {
			primary: 'var(--lnc-primary)',
			accent: 'var(--lnc-ink)',
			glow: 'var(--lnc-danger)',
		},
		silhouette: 'frau_weber',
		states: ['idle', 'confused', 'frustrated', 'relieved', 'impressed'],
		campaignAppearances: ['sicherheits_audit', 'datenschutz_check'],
	}),

	uschi: Object.freeze({
		id: 'uschi',
		name: 'Uschi',
		role: 'Sekretaerin',
		personality: 'keine Ahnung von IT, haelt Laden zusammen',
		palette: {
			primary: 'var(--lnc-amber)',
			accent: 'var(--lnc-green)',
			glow: 'var(--lnc-primary)',
		},
		silhouette: 'uschi',
		states: ['idle', 'confused', 'frustrated', 'relieved', 'impressed'],
		campaignAppearances: ['erste_woche', 'grosser_ausfall'],
	}),

	tim_azubi: Object.freeze({
		id: 'tim_azubi',
		name: 'Tim (Azubi)',
		role: 'Azubi',
		personality: 'frisch, motiviert, Anfaengerfehler',
		palette: {
			primary: 'var(--lnc-green)',
			accent: 'var(--lnc-cyan)',
			glow: 'var(--lnc-primary)',
		},
		silhouette: 'tim_azubi',
		states: ['idle', 'confused', 'frustrated', 'relieved', 'impressed'],
		campaignAppearances: ['erste_woche'],
	}),

	sven_berater: Object.freeze({
		id: 'sven_berater',
		name: 'Sven (Berater)',
		role: 'Externer Consultant',
		personality: 'redet viel, macht wenig',
		palette: {
			primary: 'var(--lnc-primary)',
			accent: 'var(--lnc-magenta)',
			glow: 'var(--lnc-amber)',
		},
		silhouette: 'sven_berater',
		states: ['idle', 'confused', 'frustrated', 'relieved', 'impressed'],
		campaignAppearances: ['grosser_ausfall'],
	}),
})

/**
 * Look up a character by ID. Returns FALLBACK_CHARACTER for unknown IDs.
 *
 * @param {string} id — character identifier
 * @returns {object} character data
 */
function getCharacter(id) {
	return CHARACTERS[id] || FALLBACK_CHARACTER
}

// Support both ESM and CJS consumption
if (typeof module !== 'undefined' && module.exports) {
	module.exports = { CHARACTERS, getCharacter, FALLBACK_CHARACTER }
}

export { CHARACTERS, getCharacter, FALLBACK_CHARACTER }
