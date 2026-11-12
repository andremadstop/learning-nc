/**
 * Audit 2026-11 (S08): CoopService::setReady() answers only the player who becomes ready LAST with
 * the state payload (scene). Everyone else keeps polling /lobby, whose payload never carries a scene,
 * even once the session status is 'playing'. applyCoopLobbyPayload returned 'lobby' for that, so the
 * poll loop never switched to /state and those players stayed in the lobby for good.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, str) => str,
	t: (_app, str) => str,
	getLanguage: () => 'de',
	isRTL: () => false,
}))

import axios from '@nextcloud/axios'
import AbenteuerMode from '../../src/components/AbenteuerMode.vue'

const methods = AbenteuerMode.methods

function makeVm() {
	const vm = {
		coopSessionId: 1,
		coopSessionCode: 'ABCD',
		coopSessionStatus: 'lobby',
		coopPlayers: [],
		coopLobbyCanStart: false,
		coopPollIntervalMs: 1500,
		coopVoting: false,
		coopVotes: {},
		coopError: '',
		coopPollTimer: null,
		coopPollMode: null,
		coopPollInFlight: false,
		coopAdvancing: false,
		loadingScene: false,
		phase: 'coop-lobby',
		syncSelectedCampaignById: vi.fn(),
		handleCoopSessionEnded: vi.fn(),
		applyCoopStatePayload: vi.fn(() => 'state'),
		extractCoopErrorMessage: (_e, fallback) => fallback,
	}
	for (const name of ['applyCoopLobbyPayload', 'applyCoopPayload', 'startCoopPolling', 'stopCoopPolling']) {
		vm[name] = methods[name].bind(vm)
	}
	return vm
}

describe('Coop lobby -> playing transition for players who were ready first', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.clearAllMocks()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('applyCoopLobbyPayload asks for /state once the session is playing', () => {
		const vm = makeVm()
		const next = vm.applyCoopLobbyPayload({ session: { id: 1, status: 'playing' }, players: [] })
		expect(next).toBe('state')
		expect(vm.coopSessionStatus).toBe('playing')
	})

	it('a session still in the lobby keeps lobby polling', () => {
		const vm = makeVm()
		expect(vm.applyCoopLobbyPayload({ session: { id: 1, status: 'lobby' }, players: [] })).toBe('lobby')
		expect(vm.phase).toBe('coop-lobby')
	})

	it('the poll loop switches from /lobby to /state and applies the scene', async () => {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/lobby')) {
				return Promise.resolve({ data: { session: { id: 1, status: 'playing' }, players: [], lobby: { can_start: true } } })
			}
			return Promise.resolve({ data: { session: { id: 1, status: 'playing' }, players: [], scene: { node: { id: 'n1' } } } })
		})
		const vm = makeVm()
		vm.startCoopPolling('lobby')
		await vi.advanceTimersByTimeAsync(0)
		// The restarted loop's immediate poll is skipped while the lobby poll is still in flight;
		// the first /state request follows one state interval later.
		await vi.advanceTimersByTimeAsync(1500)

		expect(vm.coopPollMode).toBe('state')
		expect(axios.get.mock.calls.map(([u]) => u)).toContain('/apps/learning/api/v1/coop/sessions/1/state')
		expect(vm.applyCoopStatePayload).toHaveBeenCalled()
		vm.stopCoopPolling()
	})
})
