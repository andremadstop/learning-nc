/**
 * Character classes for "Hack Through Time" — 4 classes with epoch affinity.
 *
 * Each class has bonus epochs (easier skill-checks) and weak epochs (harder).
 * The affinity system modifies pass thresholds during skill-check grading.
 *
 * @module data/characterClasses
 */

const CHARACTER_CLASSES = Object.freeze({
	phreaker: Object.freeze({
		id: 'phreaker',
		name: 'Phreaker',
		description: 'Analoges Hacking, Hardware-Tricks, Signalmanipulation',
		icon: 'phone',
		affinityEpochs: ['phone_phreaking', 'wargames'],
		weakEpochs: ['quantum_threat'],
	}),

	scriptkiddie: Object.freeze({
		id: 'scriptkiddie',
		name: 'Script-Kiddie / Ethical Hacker',
		description: 'Tools, Exploits, Web-Schwachstellen',
		icon: 'terminal',
		affinityEpochs: ['morris_worm', 'sql_injection'],
		weakEpochs: ['phone_phreaking'],
	}),

	redteamer: Object.freeze({
		id: 'redteamer',
		name: 'Red Teamer',
		description: 'APT-Simulation, Social Engineering, Lateral Movement',
		icon: 'target',
		affinityEpochs: ['apt_nation_states', 'supply_chain'],
		weakEpochs: ['phone_phreaking'],
	}),

	quantum_defender: Object.freeze({
		id: 'quantum_defender',
		name: 'Quantum Defender',
		description: 'Post-Quantum Kryptografie, Quantenschluessel',
		icon: 'shield',
		affinityEpochs: ['quantum_threat'],
		weakEpochs: ['wargames', 'morris_worm'],
	}),
})

/**
 * Get the affinity relationship between a character class and an epoch.
 *
 * @param {string} classId — character class identifier
 * @param {string} epochId — epoch identifier
 * @returns {"bonus"|"neutral"|"penalty"} affinity type
 */
function getClassAffinity(classId, epochId) {
	const cls = CHARACTER_CLASSES[classId]
	if (!cls) {
		return 'neutral'
	}
	if (cls.affinityEpochs.includes(epochId)) {
		return 'bonus'
	}
	if (cls.weakEpochs.includes(epochId)) {
		return 'penalty'
	}
	return 'neutral'
}

// Support both ESM and CJS consumption
if (typeof module !== 'undefined' && module.exports) {
	module.exports = { CHARACTER_CLASSES, getClassAffinity }
}

export { CHARACTER_CLASSES, getClassAffinity }
