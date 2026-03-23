/**
 * Epoch definitions for "Hack Through Time" — 7 IT-Security eras.
 *
 * Each epoch has: id (slug), name (German), era, yearRange, themeKey
 * (for data-epoch CSS attribute), order, description, poolFilter (tag for
 * skill-check question filtering).
 *
 * @module data/epochs/epochs
 */

const EPOCHS = Object.freeze({
	phone_phreaking: Object.freeze({
		id: 'phone_phreaking',
		name: 'Phone Phreaking',
		era: '1960er',
		yearRange: '1960-1979',
		themeKey: 'terminal-green',
		order: 1,
		description: 'Die Geburt des Hackings: Telefon-Phreaker manipulieren analoge Vermittlungsstellen mit Tonfrequenzen.',
		poolFilter: 'epoch:phone_phreaking',
	}),

	wargames: Object.freeze({
		id: 'wargames',
		name: 'WarGames',
		era: '1980er',
		yearRange: '1980-1989',
		themeKey: 'dos-blue',
		order: 2,
		description: 'BBS-Kultur, Social Engineering und die ersten Computer-Einbrueche — "Shall we play a game?"',
		poolFilter: 'epoch:wargames',
	}),

	morris_worm: Object.freeze({
		id: 'morris_worm',
		name: 'The Worm',
		era: '1990er',
		yearRange: '1988-1999',
		themeKey: 'netscape-grey',
		order: 3,
		description: 'Der Morris-Worm legt das Internet lahm, CERT wird gegruendet, die erste Verurteilung folgt.',
		poolFilter: 'epoch:morris_worm',
	}),

	sql_injection: Object.freeze({
		id: 'sql_injection',
		name: 'Bobby Tables',
		era: '2000er',
		yearRange: '2000-2009',
		themeKey: 'xp-luna',
		order: 4,
		description: 'SQL Injection, Code Red, MySpace-Worm Samy — Web-Security wird geboren, OWASP gegruendet.',
		poolFilter: 'epoch:sql_injection',
	}),

	apt_nation_states: Object.freeze({
		id: 'apt_nation_states',
		name: 'The Shadow Brokers',
		era: '2010er',
		yearRange: '2010-2019',
		themeKey: 'dark-terminal',
		order: 5,
		description: 'Stuxnet, Snowden, EternalBlue — Staaten fuehren Cyberkrieg, APT-Gruppen dominieren.',
		poolFilter: 'epoch:apt_nation_states',
	}),

	supply_chain: Object.freeze({
		id: 'supply_chain',
		name: 'Supply Chain',
		era: '2020er',
		yearRange: '2020-2029',
		themeKey: 'cloud-dashboard',
		order: 6,
		description: 'SolarWinds, Log4Shell, Prompt Injection — die Lieferkette wird zum Angriffsvektor.',
		poolFilter: 'epoch:supply_chain',
	}),

	quantum_threat: Object.freeze({
		id: 'quantum_threat',
		name: 'Quantum Dawn',
		era: 'Zukunft',
		yearRange: '2030+',
		themeKey: 'hologram-glow',
		order: 7,
		description: 'Post-Quantum-Kryptografie, Quantenschluessel und Shors Algorithmus bedrohen alles Bisherige.',
		poolFilter: 'epoch:quantum_threat',
	}),
})

/**
 * Look up an epoch by ID. Returns undefined for unknown IDs.
 *
 * @param {string} id — epoch identifier (slug)
 * @returns {object|undefined} epoch data
 */
function getEpoch(id) {
	return EPOCHS[id]
}

// Support both ESM and CJS consumption
if (typeof module !== 'undefined' && module.exports) {
	module.exports = { EPOCHS, getEpoch }
}

export { EPOCHS, getEpoch }
