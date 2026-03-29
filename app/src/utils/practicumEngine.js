/**
 * Practicum Engine — Session state machine with localStorage persistence.
 *
 * Manages step-by-step guided practice sessions for all practicum-enabled simulators.
 * Each session references existing scenario IDs from the simulator data files.
 */

import firewallSessions from '../../data/practicum/firewall-sessions.json'
import dnsSessions from '../../data/practicum/dns-sessions.json'
import routingSessions from '../../data/practicum/routing-sessions.json'
import natSessions from '../../data/practicum/nat-sessions.json'
import portscanSessions from '../../data/practicum/portscan-sessions.json'
import wiresharkSessions from '../../data/practicum/wireshark-sessions.json'
import authflowSessions from '../../data/practicum/authflow-sessions.json'
import subnetSessions from '../../data/practicum/subnet-sessions.json'
import terminalSessions from '../../data/practicum/terminal-sessions.json'

/**
 * Registry mapping simulator type keys to their session arrays.
 * Keys match SCENARIOS in simulatorShellLogic.js.
 */
export const PRACTICUM_SESSIONS = {
	firewall: firewallSessions,
	dns: dnsSessions,
	routing: routingSessions,
	nat: natSessions,
	portscan: portscanSessions,
	wireshark: wiresharkSessions,
	authflow: authflowSessions,
	subnet: subnetSessions,
	terminal: terminalSessions,
}

/**
 * Load all practicum sessions for a given simulator type.
 * @param {string} type - Simulator type key (e.g. 'firewall', 'dns')
 * @returns {Array} Array of session objects, or empty array if type unknown
 */
export function loadSessionsForSimulator(type) {
	return PRACTICUM_SESSIONS[type] || []
}

const STORAGE_PREFIX = 'lnc-practicum-'

/**
 * Practicum session state machine.
 *
 * Tracks step-by-step progress through a guided practice session,
 * records results per step, and persists/restores state via localStorage.
 */
export class PracticumEngine {

	/**
	 * @param {object} sessionData - Session object with id, title, description, steps[]
	 * @param {object} [savedState] - Optional saved state to restore from
	 */
	constructor(sessionData, savedState) {
		this._sessionData = sessionData
		if (savedState) {
			this._stepIndex = savedState.stepIndex
			this._results = savedState.results || []
			this._startedAt = savedState.startedAt
			this._completedAt = savedState.completedAt || null
		} else {
			this._stepIndex = 0
			this._results = []
			this._startedAt = Date.now()
			this._completedAt = null
		}
	}

	/** Current step object, or null if session is complete */
	get currentStep() {
		if (this._stepIndex >= this._sessionData.steps.length) {
			return null
		}
		return this._sessionData.steps[this._stepIndex]
	}

	/** 0-based current step position */
	get stepIndex() {
		return this._stepIndex
	}

	/** Total number of steps in the session */
	get totalSteps() {
		return this._sessionData.steps.length
	}

	/** Session ID from the session data */
	get sessionId() {
		return this._sessionData.id
	}

	/** True when all steps have been completed */
	get isComplete() {
		return this._stepIndex >= this._sessionData.steps.length
	}

	/**
	 * Record result for current step and advance.
	 * No-op if session is already complete.
	 * @param {{ passed: boolean, score: number }} result
	 */
	recordResult({ passed, score }) {
		if (this.isComplete) {
			return
		}
		this._results.push({ passed, score })
		this._stepIndex++
		if (this.isComplete) {
			this._completedAt = Date.now()
		}
	}

	/** Session summary with score as "X/Y" string */
	get summary() {
		const correct = this._results.filter(r => r.passed).length
		const total = this._sessionData.steps.length
		return {
			sessionId: this._sessionData.id,
			results: this._results,
			score: `${correct}/${total}`,
			startedAt: this._startedAt,
			completedAt: this._completedAt,
		}
	}

	/** Persist current state to localStorage */
	persist() {
		const state = {
			sessionId: this._sessionData.id,
			stepIndex: this._stepIndex,
			results: this._results,
			startedAt: this._startedAt,
			completedAt: this._completedAt,
		}
		localStorage.setItem(STORAGE_PREFIX + this._sessionData.id, JSON.stringify(state))
	}

	/**
	 * Restore a previously saved engine from localStorage.
	 * @param {string} sessionId
	 * @param {object} sessionData - The full session data object
	 * @returns {PracticumEngine|null} Restored engine or null if no saved state
	 */
	static restore(sessionId, sessionData) {
		const raw = localStorage.getItem(STORAGE_PREFIX + sessionId)
		if (!raw) {
			return null
		}
		const saved = JSON.parse(raw)
		return new PracticumEngine(sessionData, saved)
	}

	/** Clear saved state and reset to step 0 */
	reset() {
		localStorage.removeItem(STORAGE_PREFIX + this._sessionData.id)
		this._stepIndex = 0
		this._results = []
		this._startedAt = Date.now()
		this._completedAt = null
	}

}
