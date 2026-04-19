/**
 * Character Registry — campaign figures plus user-selectable VirtuProf skins.
 *
 * Each character has: id, name, role, personality, palette, silhouette, states,
 * campaignAppearances, user_selectable, category, preview_thumbnail_svg.
 */

const DEFAULT_META = Object.freeze({
	user_selectable: false,
	category: 'campaign',
	preview_thumbnail_svg: '',
})

function createPreviewSvg(primary, accent, glow) {
	return [
		'<svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">',
		`<circle cx="24" cy="15" r="8" fill="${primary}" />`,
		`<path d="M14 25 Q24 20 34 25 L37 40 H11 Z" fill="${accent}" />`,
		`<circle cx="36" cy="12" r="4" fill="${glow}" opacity="0.8" />`,
		'</svg>',
	].join('')
}

function defineCharacter(character) {
	return Object.freeze({
		...DEFAULT_META,
		...character,
		palette: Object.freeze(character.palette),
		states: Object.freeze([...(character.states || [])]),
		campaignAppearances: Object.freeze([...(character.campaignAppearances || [])]),
	})
}

const FALLBACK_CHARACTER = defineCharacter({
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

const CHARACTERS = Object.freeze({
	nova: defineCharacter({
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
		states: ['idle', 'thinking', 'talk', 'celebrate', 'explain', 'alert'],
		campaignAppearances: ['*'],
		user_selectable: true,
		category: 'assistant',
		preview_thumbnail_svg: createPreviewSvg('var(--lnc-primary)', 'var(--lnc-cyan)', 'var(--lnc-cyan)'),
	}),

	prof_lern_classic: defineCharacter({
		id: 'prof_lern_classic',
		name: 'Prof. Lern Classic',
		role: 'VirtuProf Classic',
		personality: 'freundlich, nostalgisch, erklaert geduldig',
		palette: {
			primary: '#f3c7a6',
			accent: '#2c6c9f',
			glow: '#f2c230',
		},
		silhouette: 'prof_lern_classic',
		states: ['idle', 'talk', 'wave', 'celebrate'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'assistant',
		preview_thumbnail_svg: createPreviewSvg('#f3c7a6', '#2c6c9f', '#f2c230'),
	}),

	theoretiker: defineCharacter({
		id: 'theoretiker',
		name: 'Der Theoretiker',
		role: 'Science Hero',
		personality: 'intensiv, gedankenschnell, elektrisiert den Raum',
		palette: {
			primary: 'var(--lnc-amber)',
			accent: '#8b5e34',
			glow: '#c8ff5a',
		},
		silhouette: 'theoretiker',
		states: ['idle', 'thinking', 'wave', 'celebrate'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'scholar',
		preview_thumbnail_svg: createPreviewSvg('var(--lnc-amber)', '#8b5e34', '#c8ff5a'),
	}),

	kosmologe: defineCharacter({
		id: 'kosmologe',
		name: 'Der Kosmologe',
		role: 'Science Hero',
		personality: 'ruhig, mutig, praesentiert Komplexitaet mit Leichtigkeit',
		palette: {
			primary: '#274d8f',
			accent: '#92a7c2',
			glow: '#67d8ff',
		},
		silhouette: 'kosmologe',
		states: ['idle', 'thinking', 'wave', 'celebrate'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'scholar',
		preview_thumbnail_svg: createPreviewSvg('#274d8f', '#92a7c2', '#67d8ff'),
	}),

	popularisierer: defineCharacter({
		id: 'popularisierer',
		name: 'Der Astrophysik-Popularisierer',
		role: 'Science Hero',
		personality: 'charismatisch, warm, macht Kosmos greifbar',
		palette: {
			primary: '#b83fd7',
			accent: '#5a2b74',
			glow: '#ffd86a',
		},
		silhouette: 'popularisierer',
		states: ['idle', 'thinking', 'wave', 'celebrate'],
		campaignAppearances: [],
		user_selectable: true,
		category: 'scholar',
		preview_thumbnail_svg: createPreviewSvg('#b83fd7', '#5a2b74', '#ffd86a'),
	}),

	architect: defineCharacter({
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

	security: defineCharacter({
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

	sysadmin: defineCharacter({
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

	helpdesk: defineCharacter({
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

	ghostline: defineCharacter({
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

	klaus_dau: defineCharacter({
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

	dr_hartmann: defineCharacter({
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

	frau_weber: defineCharacter({
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

	uschi: defineCharacter({
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

	tim_azubi: defineCharacter({
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

	sven_berater: defineCharacter({
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

export const VIRTUPROF_SKIN_IDS = Object.freeze([
	'nova',
	'prof_lern_classic',
	'theoretiker',
	'kosmologe',
	'popularisierer',
])

export function getCharacter(id) {
	return CHARACTERS[id] || FALLBACK_CHARACTER
}

export function resolveVirtuProfSkinId(id) {
	return VIRTUPROF_SKIN_IDS.includes(id) ? id : 'nova'
}

export function getSelectableVirtuProfSkins() {
	return VIRTUPROF_SKIN_IDS
		.map((id) => getCharacter(id))
		.filter((character) => character.user_selectable === true)
}

if (typeof module !== 'undefined' && module.exports) {
	module.exports = {
		CHARACTERS,
		FALLBACK_CHARACTER,
		VIRTUPROF_SKIN_IDS,
		getCharacter,
		getSelectableVirtuProfSkins,
		resolveVirtuProfSkinId,
	}
}

export {
	CHARACTERS,
	FALLBACK_CHARACTER,
}
