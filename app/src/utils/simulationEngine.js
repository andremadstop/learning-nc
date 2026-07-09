/**
 * Reine Simulations-/Stub-Builder für AbenteuerMode.vue (Zero-Behavior-Change extrahiert).
 * `this`-frei; einzige Abhängigkeit ist die i18n-Funktion translate (als t importiert),
 * wie vom Design-Spec §5.C gefordert (im Component lief das über das globale t).
 */
import { translate as t } from '@nextcloud/l10n'

/** Lokale Fallback-Szene, falls keine Server-Szene geladen werden konnte. */
export function makeStubScene() {
	return {
		id: 's1_ankunft',
		title: t('learning', 'Ankunft'),
		narrative: t('learning', 'Du betrittst das Gebäude. Die Rezeption ist chaotisch — Telefone klingeln, niemand hat Internet. Dr. Weber steht am Aufzug: "Mein Meeting um 8 ist per Video. Schaffen Sie das?"'),
		npc_dialog: {
			speaker: 'dr_weber',
			text: t('learning', 'Mein Meeting um 8 ist per Video. Schaffen Sie das?'),
		},
		choices: [
			{ id: 'c1_serverraum', icon: '🖥', text: t('learning', 'Ich gehe direkt in den Serverraum') },
			{ id: 'c1_befragung', icon: '🗣', text: t('learning', 'Erst befrage ich die Mitarbeiter') },
			{ id: 'c1_schraenke', icon: '📦', text: t('learning', 'Ich prüfe die Netzwerkschränke auf dieser Etage') },
		],
	}
}

/** Lokale Fallback-Skill-Check-Frage. */
export function makeStubQuestion() {
	return {
		id: 'stub_q1',
		text: t('learning', 'Was ist der erste Schritt bei der CompTIA 7-Step Troubleshooting-Methodik?'),
		answers: [
			{ id: 'a1', text: t('learning', 'Problem identifizieren'), is_correct: true },
			{ id: 'a2', text: t('learning', 'Theorie aufstellen'), is_correct: false },
			{ id: 'a3', text: t('learning', 'Plan erstellen'), is_correct: false },
			{ id: 'a4', text: t('learning', 'Theorie testen'), is_correct: false },
		],
		explanation: t('learning', 'Der erste Schritt ist immer die Problemidentifikation — Symptome sammeln und das Problem genau beschreiben.'),
	}
}

/** Baut die PBQ-Config für eine Geräte-Platzierungs-Simulation (mit Fallbacks). */
export function buildPlacementSimulationConfig(simulation) {
	const fallbackPositions = [
		{ id: 'firewall', label: t('learning', 'Perimeter'), correct: 'Firewall', x_pct: 20, y_pct: 50 },
		{ id: 'core_switch', label: t('learning', 'Core'), correct: 'Core Switch', x_pct: 50, y_pct: 50 },
		{ id: 'access_layer', label: t('learning', 'Access'), correct: 'Access Switch', x_pct: 80, y_pct: 50 },
	]
	const rawPositions = Array.isArray(simulation.positions) && simulation.positions.length
		? simulation.positions
		: fallbackPositions
	const positions = rawPositions.map((position, index) => ({
		id: position.id || `slot_${index + 1}`,
		label: position.label || position.id || t('learning', 'Position {n}', { n: index + 1 }),
		correct: position.correct || position.correctInput || '',
		x_pct: Number.isFinite(Number(position.x_pct)) ? Number(position.x_pct) : undefined,
		y_pct: Number.isFinite(Number(position.y_pct)) ? Number(position.y_pct) : undefined,
	}))
	const fallbackOptions = positions
		.map((position) => position.correct)
		.filter((value) => typeof value === 'string' && value.trim().length)
	const deviceOptions = Array.isArray(simulation.device_options) && simulation.device_options.length
		? simulation.device_options
		: fallbackOptions

	return {
		instructions: [simulation.description || t('learning', 'Platziere die Netzwerkgeräte korrekt.')],
		positions,
		device_options: [...new Set(deviceOptions)],
		scoring_mode: simulation.scoring_mode || 'strict',
		background_image: simulation.background_image || null,
		scenario_image: simulation.scenario_image || simulation.background_image || null,
		topology: simulation.topology || null,
	}
}

/** Baut die PBQ-Frage für eine Simulation je nach simulation.type (Fallback: diagnostic). */
export function buildSimQuestion(simulation) {
	const typeMap = {
		cli: {
			pbq_subtype: 'cli',
			pbq_config: {
				hint: simulation.description || t('learning', 'Nutze die Kommandozeile, um das Problem zu lösen.'),
				domain: simulation.domain || 'cisco_ios',
				terminals: simulation.terminals || [
					{ name: 'SW1' },
				],
				command_outputs: simulation.command_outputs || {},
				evaluation: simulation.evaluation || { required_commands: [] },
			},
		},
		switch_config: {
			pbq_subtype: 'switch_config',
			pbq_config: {
				instructions: [simulation.description || t('learning', 'Konfiguriere die Netzwerkgeräte korrekt.')],
				switches: [
					{
						name: 'SW-CORE',
						ports: [
							{ id: 'Gi0/1', label: 'Gi0/1 (Trunk)', correct: 'trunk' },
							{ id: 'Gi0/2', label: 'Gi0/2 (Access VLAN 10)', correct: 'access_vlan10' },
						],
					},
				],
			},
		},
		network_device_placement: {
			pbq_subtype: 'placement',
			pbq_config: buildPlacementSimulationConfig(simulation),
		},
		diagnostic: {
			pbq_subtype: 'diagnostic',
			pbq_config: {
				instructions: [simulation.description || t('learning', 'Analysiere die Netzwerkprobleme.')],
				findings: [
					{ id: 'f1', description: t('learning', 'Routing Loop erkannt'), correct_action: 'fix_route' },
					{ id: 'f2', description: t('learning', 'Falsche VLAN-Konfiguration'), correct_action: 'fix_vlan' },
				],
				actions: [
					{ id: 'fix_route', label: t('learning', 'Route korrigieren') },
					{ id: 'fix_vlan',  label: t('learning', 'VLAN anpassen') },
					{ id: 'ignore',    label: t('learning', 'Ignorieren') },
				],
			},
		},
	}

	const template = typeMap[simulation.type] || typeMap.diagnostic
	return {
		id: 'sim_' + (simulation.type || 'generic'),
		text: simulation.description || t('learning', 'Netzwerk-Simulation'),
		...template,
	}
}

/** Ermittelt die Gesamtzahl der Teil-Aufgaben einer PBQ-Frage (für die 50%-Pass-Regel). */
export function calcSimTotal(question) {
	const cfg = question.pbq_config || {}
	switch (question.pbq_subtype) {
		case 'switch_config':
			return (cfg.switches || []).reduce(
				(sum, sw) => sum + (sw.ports || []).filter(p => !!p.correct).length,
				0,
			)
		case 'placement':
			return (cfg.positions || []).length
		case 'diagnostic':
			return (cfg.findings || []).length
		default:
			return 1
	}
}
