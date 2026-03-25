import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const COOP_BASE_URL = '/apps/learning/api/v1/coop/sessions'

function normalizePlayers(players) {
	return Array.isArray(players) ? players : []
}

function normalizePlayerVotes(votes) {
	if (!votes || typeof votes !== 'object' || typeof votes.player_votes !== 'object') {
		return {}
	}

	return Object.fromEntries(
		Object.entries(votes.player_votes)
			.filter(([userId, choiceId]) => userId && choiceId)
			.map(([userId, choiceId]) => [String(userId), String(choiceId)]),
	)
}

function normalizeVoteCounts(votes, playerVotes) {
	if (!votes || typeof votes !== 'object') {
		return new Map()
	}

	if (Array.isArray(votes.counts)) {
		return new Map(
			votes.counts
				.filter(entry => entry && entry.edge_id)
				.map(entry => [String(entry.edge_id), Number(entry.count) || 0]),
		)
	}

	const counts = new Map()
	for (const choiceId of Object.values(playerVotes)) {
		counts.set(choiceId, (counts.get(choiceId) || 0) + 1)
	}
	return counts
}

function normalizeChoices(votes, choices) {
	if (Array.isArray(choices) && choices.length > 0) {
		return choices
	}

	if (!votes || typeof votes !== 'object' || !Array.isArray(votes.counts)) {
		return []
	}

	return votes.counts.map(entry => ({
		id: String(entry.edge_id || ''),
		label: String(entry.edge_id || ''),
	}))
}

export async function createCoopSession(campaignId, options = {}) {
	const payload = {
		campaignId,
		characterId: options.characterId || 'helpdesk',
		maxPlayers: options.maxPlayers || 4,
	}
	const { data } = await axios.post(generateUrl(COOP_BASE_URL), payload)
	return data
}

export async function joinCoopSession(code, options = {}) {
	const payload = {
		characterId: options.characterId || 'helpdesk',
	}
	if (options.displayName) {
		payload.displayName = options.displayName
	}
	const { data } = await axios.post(
		generateUrl(`${COOP_BASE_URL}/${encodeURIComponent(String(code || ''))}/join`),
		payload,
	)
	return data
}

export async function setCoopReady(sessionId, options = {}) {
	const payload = {}
	if (typeof options.ready === 'boolean') {
		payload.ready = options.ready
	}
	if (options.action) {
		payload.action = options.action
	}
	const { data } = await axios.post(
		generateUrl(`${COOP_BASE_URL}/${sessionId}/ready`),
		payload,
	)
	return data
}

export async function pollCoopLobby(sessionId) {
	const { data } = await axios.get(generateUrl(`${COOP_BASE_URL}/${sessionId}/lobby`))
	return data
}

export async function pollCoopState(sessionId) {
	const { data } = await axios.get(generateUrl(`${COOP_BASE_URL}/${sessionId}/state`))
	return data
}

export async function submitVote(sessionId, edgeId, payload = {}) {
	const body = { ...payload }
	if (edgeId) {
		body.edgeId = edgeId
	}
	const { data } = await axios.post(generateUrl(`${COOP_BASE_URL}/${sessionId}/vote`), body)
	return data
}

export function isAllVoted(votes, players) {
	const normalizedPlayers = normalizePlayers(players)
	if (normalizedPlayers.length === 0) {
		return false
	}

	const playerVotes = normalizePlayerVotes(votes)
	const votesCast = Number(votes && votes.votes_cast) || Object.keys(playerVotes).length
	return votesCast >= normalizedPlayers.length
}

export function computeVoteDisplay(votes, players, choices = []) {
	const normalizedPlayers = normalizePlayers(players)
	const normalizedChoices = normalizeChoices(votes, choices)
	const playerVotes = normalizePlayerVotes(votes)
	const counts = normalizeVoteCounts(votes, playerVotes)
	const votesCast = Number(votes && votes.votes_cast) || Object.keys(playerVotes).length
	const totalPlayers = normalizedPlayers.length || Number(votes && votes.total_players) || 0
	const waitingPlayers = normalizedPlayers.filter(player => !playerVotes[player.user_id])
	const highestVoteCount = normalizedChoices.reduce(
		(max, choice) => Math.max(max, counts.get(String(choice.id)) || 0),
		0,
	)

	const byChoice = normalizedChoices.map(choice => {
		const choiceId = String(choice.id || choice.choiceId || '')
		const voterIds = normalizedPlayers
			.filter(player => playerVotes[player.user_id] === choiceId)
			.map(player => player.user_id)
		const voteCount = counts.get(choiceId) || 0

		return {
			choiceId,
			label: choice.label || choice.text || choiceId,
			votes: voteCount,
			voterIds,
			voterNames: normalizedPlayers
				.filter(player => voterIds.includes(player.user_id))
				.map(player => player.display_name || player.user_id),
			isLeading: voteCount > 0 && voteCount === highestVoteCount,
			hasMajority: totalPlayers > 0 && voteCount > (totalPlayers / 2),
		}
	})

	const leadingChoices = byChoice.filter(choice => choice.isLeading).map(choice => choice.choiceId)

	return {
		totalPlayers,
		votesCast,
		allVoted: isAllVoted(votes, normalizedPlayers),
		waitingPlayerIds: waitingPlayers.map(player => player.user_id),
		waitingPlayers,
		leadingChoiceId: leadingChoices.length === 1 ? leadingChoices[0] : null,
		leadingChoiceIds: leadingChoices,
		byChoice,
	}
}
