/**
 * Character Registry — All 12 figures for Learning-NC campaign system.
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
	user_selectable: false,
	category: 'campaign',
	preview_thumbnail_svg: null,
})

/**
 * All 12 characters: 6 heroes + 6 workplace figures.
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
		user_selectable: true,
		category: 'hero',
		preview_thumbnail_svg: null,
	}),

	prof_lern_classic: Object.freeze({
		id: 'prof_lern_classic',
		name: 'Prof. Lern',
		role: 'Klassischer Lernbegleiter',
		personality: 'Buchgelehrt, freundlich, klassische Schulhaus-Aesthetik. Wartet hinter einem Buch, schaut den Cursor an, winkt bei Klick.',
		palette: {
			primary: 'var(--lnc-primary)',
			accent: 'var(--lnc-warning)',
			glow: 'var(--lnc-cyan)',
		},
		silhouette: 'prof_lern',
		states: ['idle', 'wave', 'thinking'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'classic',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
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
		user_selectable: false,
		category: 'campaign',
		preview_thumbnail_svg: null,
	}),

	// ── Scholar Archetypes ──────────────────────────────────────────────

	theoretiker: Object.freeze({
		id: 'theoretiker',
		name: 'Der Theoretiker',
		role: 'Grundlagen-Denker',
		personality: 'Ruhig, praezise, liebt Modelle und Herleitungen. Fragt nach Annahmen, definiert Begriffe sauber und macht aus Chaos ein Diagramm. Humor trocken, nie abgehoben.',
		palette: {
			primary: 'var(--lnc-amber)',
			accent: 'var(--lnc-text-muted)',
			glow: 'var(--lnc-green)',
		},
		silhouette: 'theoretiker',
		states: ['idle', 'wave', 'celebrate'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'scholar',
		preview_thumbnail_svg: null,
	}),

	kosmologe: Object.freeze({
		id: 'kosmologe',
		name: 'Der Kosmologe',
		role: 'System-Zusammenhaenge',
		personality: 'Warm, weitblickend, denkt in grossen Skalen ohne den Boden zu verlieren. Verbindet Einzelfakten zu Landschaften, zeigt Abhaengigkeiten und warnt vor Tunnelblick.',
		palette: {
			primary: 'var(--lnc-primary)',
			accent: 'var(--lnc-ink)',
			glow: 'var(--lnc-cyan)',
		},
		silhouette: 'kosmologe',
		states: ['idle', 'wave', 'celebrate'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'scholar',
		preview_thumbnail_svg: null,
	}),

	popularisierer: Object.freeze({
		id: 'popularisierer',
		name: 'Der Astrophysik-Popularisierer',
		role: 'Anschaulicher Uebersetzer',
		personality: 'Energiegeladen, klar, buehnentauglich ohne Show-Gehabe. Uebersetzt abstrakte Technik in einpraegsame Bilder, stellt Rueckfragen und holt Lernende aktiv ab.',
		palette: {
			primary: 'var(--lnc-magenta)',
			accent: 'var(--lnc-warning)',
			glow: 'var(--lnc-magenta)',
		},
		silhouette: 'popularisierer',
		states: ['idle', 'wave', 'celebrate'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'scholar',
		preview_thumbnail_svg: null,
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
