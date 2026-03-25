import { describe, it, expect } from 'vitest'
import {
	advanceBotPhase,
	applyResult,
	computeBotScoring,
	computeBotSummary,
	createBotSession,
	getKlausAvatarState,
	getKlausReaction,
	submitDiagnosis,
	submitFix,
} from '../../src/utils/dauBotEngine.js'

const scenario = {
	scenario_id: 'bot-correction-confront_blind',
	bot_action: 'DAU-Bot hat den Switch neu gestartet, weil das bei Druckern auch hilft.',
	symptom: 'Jetzt blinken alle Ports gleichzeitig und niemand weiss warum.',
	error_options: [
		{ id: 'allow_any_any', label: 'Die Firewall-Regel erlaubt jetzt einfach alles.' },
		{ id: 'dns_typo', label: 'Der DNS-Eintrag zeigt ins Nirgendwo.' },
	],
	fix_options: [
		{ id: 'restrict_firewall_rule', label: 'Die Regel auf die benoetigten Ports begrenzen.' },
		{ id: 'restart_again', label: 'Zur Sicherheit nochmal neu starten.' },
	],
}

describe('dauBotEngine', () => {
	it('creates a new diagnose session from a scenario', () => {
		const session = createBotSession(scenario)

		expect(session.phase).toBe('diagnose')
		expect(session.scenario).toBe(scenario)
		expect(session.selectedErrorId).toBeNull()
		expect(session.selectedFixId).toBeNull()
		expect(session.passed).toBeNull()
	})

	it('transitions to fix phase when a diagnosis is selected', () => {
		const session = submitDiagnosis(createBotSession(scenario), 'allow_any_any')

		expect(session.phase).toBe('fix')
		expect(session.selectedErrorId).toBe('allow_any_any')
		expect(session.selectedFixId).toBeNull()
	})

	it('transitions to awaiting_result when a fix is selected', () => {
		const base = submitDiagnosis(createBotSession(scenario), 'allow_any_any')
		const session = submitFix(base, 'restrict_firewall_rule')

		expect(session.phase).toBe('awaiting_result')
		expect(session.selectedErrorId).toBe('allow_any_any')
		expect(session.selectedFixId).toBe('restrict_firewall_rule')
	})

	it('transitions to summary with passed=true', () => {
		const base = submitFix(
			submitDiagnosis(createBotSession(scenario), 'allow_any_any'),
			'restrict_firewall_rule',
		)
		const session = applyResult(base, { passed: true })

		expect(session.phase).toBe('summary')
		expect(session.passed).toBe(true)
	})

	it('transitions to summary with passed=false', () => {
		const base = submitFix(
			submitDiagnosis(createBotSession(scenario), 'dns_typo'),
			'restart_again',
		)
		const session = applyResult(base, { passed: false })

		expect(session.phase).toBe('summary')
		expect(session.passed).toBe(false)
	})

	it('returns relieved diagnose reaction text for positive feedback', () => {
		expect(getKlausReaction('diagnose', true)).toContain('Puh')
	})

	it('returns confused diagnose reaction text for negative feedback', () => {
		expect(getKlausReaction('diagnose', false)).toContain('bist du sicher')
	})

	it('returns impressed fix reaction text for positive feedback', () => {
		expect(getKlausReaction('fix', true)).toContain('Wow')
	})

	it('returns frustrated fix reaction text for negative feedback', () => {
		expect(getKlausReaction('fix', false)).toContain('ALLES rot')
	})

	it('builds a positive summary from the chosen options', () => {
		const session = applyResult(
			submitFix(
				submitDiagnosis(createBotSession(scenario), 'allow_any_any'),
				'restrict_firewall_rule',
			),
			{ passed: true },
		)

		expect(computeBotSummary(session)).toContain('Firewall-Regel erlaubt')
		expect(computeBotSummary(session)).toContain('Klaus')
	})

	it('maps avatar states per phase and result', () => {
		expect(getKlausAvatarState('diagnose')).toBe('confused')
		expect(getKlausAvatarState('fix')).toBe('alert')
		expect(getKlausAvatarState('awaiting_result')).toBe('thinking')
		expect(getKlausAvatarState('summary', true)).toBe('relieved')
		expect(getKlausAvatarState('summary', false)).toBe('frustrated')
	})

	it('creates a safe empty session for invalid scenarios', () => {
		const session = createBotSession(null)
		expect(session.scenario).toEqual({})
		expect(session.phase).toBe('diagnose')
	})

	it('falls back to the selected ids when option lists are empty', () => {
		const session = applyResult({
			...createBotSession({ error_options: [], fix_options: [] }),
			selectedErrorId: 'ghost_error',
			selectedFixId: 'ghost_fix',
		}, { passed: false })

		expect(computeBotSummary(session)).toContain('ghost_error')
		expect(computeBotSummary(session)).toContain('ghost_fix')
	})

	it('keeps a summary session unchanged on invalid phase advance', () => {
		const summary = applyResult(createBotSession(scenario), { passed: true })
		expect(advanceBotPhase(summary, 'anything')).toEqual(summary)
	})

	it('uses the default reaction for unknown phases', () => {
		expect(getKlausReaction('invalid-phase', false)).toContain('nichts mehr')
	})

	it('returns idle avatar state for unknown phases', () => {
		expect(getKlausAvatarState('invalid-phase')).toBe('idle')
	})

	it('supports a double diagnosis submit by overwriting the selected error', () => {
		const first = submitDiagnosis(createBotSession(scenario), 'allow_any_any')
		const second = submitDiagnosis(first, 'dns_typo')
		expect(second.phase).toBe('fix')
		expect(second.selectedErrorId).toBe('dns_typo')
	})

	it('supports a double fix submit by overwriting the selected fix', () => {
		const base = submitDiagnosis(createBotSession(scenario), 'allow_any_any')
		const first = submitFix(base, 'restrict_firewall_rule')
		const second = submitFix(first, 'restart_again')
		expect(second.phase).toBe('awaiting_result')
		expect(second.selectedFixId).toBe('restart_again')
	})

	it('reads scoring deltas from server responses', () => {
		expect(computeBotScoring({
			score_change: 25,
			reputation_changes: { team: 1 },
		})).toEqual({
			scoreChange: 25,
			reputationChanges: { team: 1 },
		})
	})

	it('reads nested bot correction pass states', () => {
		const session = applyResult(createBotSession(scenario), {
			bot_correction: { passed: true },
		})
		expect(session.phase).toBe('summary')
		expect(session.passed).toBe(true)
	})
})
