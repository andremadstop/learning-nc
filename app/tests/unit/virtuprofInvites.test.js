import { describe, it, expect } from 'vitest'
import { inviteStatusLabel, mapInviteCard } from '../../src/utils/virtuprof-invites.js'

// Charakterisierungs-Tests: fixieren das Verhalten aus VirtuProf.vue. Zero-Behavior-Change.
// vt-Stub gibt Key (+ Params) sichtbar zurück, um die durchgereichten i18n-Keys zu prüfen.
const vt = (key, params) => (params ? `${key}::${JSON.stringify(params)}` : key)

describe('inviteStatusLabel', () => {
	it('can_open_duel → "Ready"', () => {
		expect(inviteStatusLabel({ can_open_duel: true })).toBe('Ready')
	})
	it('kapitalisiert den Status', () => {
		expect(inviteStatusLabel({ status: 'open' })).toBe('Open')
		expect(inviteStatusLabel({ status: 'accepted' })).toBe('Accepted')
	})
	it('ohne Status → "Open"', () => {
		expect(inviteStatusLabel({})).toBe('Open')
	})
})

describe('mapInviteCard', () => {
	it('incoming invite mit accept+decline', () => {
		const card = mapInviteCard({
			id: 7, direction: 'incoming', inviter_uid: 'alice', invitee_uid: 'me',
			status: 'open', can_accept: true, can_decline: true, pool_name: 'Net+', pool_id: 3, created_at: 'x',
		}, vt)
		expect(card.id).toBe(7)
		expect(card.direction).toBe('incoming')
		expect(card.statusLabel).toBe('Open')
		expect(card.title).toBe('Challenge from {user}::{"user":"alice"}')
		expect(card.itemActions.map(a => a.type)).toEqual(['accept-invite', 'decline-invite'])
		expect(card.itemActions[0].inviteId).toBe(7)
	})

	it('outgoing invite mit cancel, pool_name-Fallback auf #id', () => {
		const card = mapInviteCard({
			id: 9, direction: 'outgoing', invitee_uid: 'bob', status: 'open', can_cancel: true, pool_id: 5,
		}, vt)
		expect(card.direction).toBe('outgoing')
		expect(card.title).toBe('Challenge to {user}::{"user":"bob"}')
		expect(card.subtitle).toContain('#5')
		expect(card.itemActions.map(a => a.type)).toEqual(['cancel-invite'])
	})

	it('offenes Duell → open-duel-Action + Ready-Status', () => {
		const card = mapInviteCard({
			id: 1, direction: 'incoming', inviter_uid: 'x', status: 'ready',
			can_open_duel: true, course_id: 2, duel_code: 'ABC', pool_id: 1,
		}, vt)
		expect(card.statusLabel).toBe('Ready')
		expect(card.itemActions.find(a => a.type === 'open-duel')).toMatchObject({ courseId: 2, duelCode: 'ABC' })
		expect(card.message).toContain('ready')
	})
})
