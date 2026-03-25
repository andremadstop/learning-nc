import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: url => url,
}))

import axios from '@nextcloud/axios'
import {
	computeVoteDisplay,
	createCoopSession,
	isAllVoted,
	joinCoopSession,
	pollCoopState,
	setCoopReady,
	submitVote,
} from '../../src/utils/coopEngine.js'

describe('coopEngine API wrappers', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('creates a coop session with campaign and character payload', async () => {
		axios.post.mockResolvedValue({ data: { session: { id: 17, code: 'ABCD1234' } } })

		const result = await createCoopSession('der_grosse_ausfall', {
			characterId: 'security',
			maxPlayers: 3,
		})

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/learning/api/v1/coop/sessions',
			expect.objectContaining({
				campaignId: 'der_grosse_ausfall',
				characterId: 'security',
				maxPlayers: 3,
			}),
		)
		expect(result.session.code).toBe('ABCD1234')
	})

	it('joins a coop session by code', async () => {
		axios.post.mockResolvedValue({ data: { session: { id: 17, code: 'ABCD1234' } } })

		await joinCoopSession('abcd1234', {
			characterId: 'sysadmin',
			displayName: 'Kai',
		})

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/learning/api/v1/coop/sessions/abcd1234/join',
			expect.objectContaining({
				characterId: 'sysadmin',
				displayName: 'Kai',
			}),
		)
	})

	it('polls coop state by session id', async () => {
		axios.get.mockResolvedValue({ data: { session: { id: 55 } } })

		await pollCoopState(55)

		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/v1/coop/sessions/55/state')
	})

	it('submits ready state and session end action', async () => {
		axios.post.mockResolvedValue({ data: { session: { status: 'finished' } } })

		await setCoopReady(55, {
			ready: true,
			action: 'end',
		})

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/learning/api/v1/coop/sessions/55/ready',
			{ ready: true, action: 'end' },
		)
	})

	it('submits a vote and forwards simulator payloads', async () => {
		axios.post.mockResolvedValue({ data: { votes: { votes_cast: 2 } } })

		await submitVote(55, 'edge-firewall', {
			simulator_completed: true,
			simulator_passed: true,
			simulator_score: 92,
		})

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/learning/api/v1/coop/sessions/55/vote',
			expect.objectContaining({
				edgeId: 'edge-firewall',
				simulator_completed: true,
				simulator_passed: true,
				simulator_score: 92,
			}),
		)
	})
})

describe('coopEngine vote display helpers', () => {
	const players = [
		{ user_id: 'alice', display_name: 'Alice' },
		{ user_id: 'bob', display_name: 'Bob' },
		{ user_id: 'mia', display_name: 'Mia' },
	]

	const choices = [
		{ id: 'edge-a', label: 'Firewall rollback' },
		{ id: 'edge-b', label: 'DNS zuerst pruefen' },
		{ id: 'edge-c', label: 'Sabine anrufen' },
	]

	it('builds per-choice vote display and waiting players', () => {
		const votes = {
			total_players: 3,
			votes_cast: 2,
			counts: [
				{ edge_id: 'edge-a', count: 2 },
				{ edge_id: 'edge-b', count: 0 },
			],
			player_votes: {
				alice: 'edge-a',
				bob: 'edge-a',
			},
		}

		const result = computeVoteDisplay(votes, players, choices)

		expect(result.totalPlayers).toBe(3)
		expect(result.votesCast).toBe(2)
		expect(result.allVoted).toBe(false)
		expect(result.leadingChoiceId).toBe('edge-a')
		expect(result.waitingPlayerIds).toEqual(['mia'])
		expect(result.byChoice[0]).toEqual(
			expect.objectContaining({
				choiceId: 'edge-a',
				votes: 2,
				isLeading: true,
				hasMajority: true,
				voterNames: ['Alice', 'Bob'],
			}),
		)
	})

	it('reports all voted when all active players have submitted', () => {
		const votes = {
			player_votes: {
				alice: 'edge-a',
				bob: 'edge-b',
				mia: 'edge-b',
			},
		}

		expect(isAllVoted(votes, players)).toBe(true)
	})

	it('returns multiple leading choices on a tie', () => {
		const votes = {
			total_players: 2,
			votes_cast: 2,
			counts: [
				{ edge_id: 'edge-a', count: 1 },
				{ edge_id: 'edge-b', count: 1 },
			],
			player_votes: {
				alice: 'edge-a',
				bob: 'edge-b',
			},
		}

		const result = computeVoteDisplay(votes, players.slice(0, 2), choices)

		expect(result.leadingChoiceId).toBeNull()
		expect(result.leadingChoiceIds).toEqual(['edge-a', 'edge-b'])
	})

	it('derives counts from player votes when backend count rows are absent', () => {
		const votes = {
			player_votes: {
				alice: 'edge-b',
				bob: 'edge-b',
			},
		}

		const result = computeVoteDisplay(votes, players.slice(0, 2), choices)
		const dnsChoice = result.byChoice.find(choice => choice.choiceId === 'edge-b')

		expect(dnsChoice.votes).toBe(2)
		expect(dnsChoice.isLeading).toBe(true)
	})
})
