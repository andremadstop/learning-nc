/**
 * NOVA Reaction Engine — maps user events to visual/audio reactions.
 * Source: CHARACTER_BIBLE.md Section 7+9, VIRTPROF_REACTION_LOGIC.md
 */

import { novaAudio } from './nova-audio-manager.js'

const EVENT_MAP = {
	'answer-correct': { animation: 'talk', emotion: 'happy', sound: 'success', duration: 2000 },
	'answer-wrong': { animation: 'talk', emotion: 'sad', sound: 'error', duration: 2000 },
	'streak-5': { animation: 'celebrate', emotion: 'celebrate', sound: 'success', duration: 3000, cooldown: 'session' },
	'streak-lost': { animation: 'talk', emotion: 'sad', sound: null, duration: 2000, cooldown: 'session' },
	'badge-earned': { animation: 'celebrate', emotion: 'celebrate', sound: 'success', duration: 4000 },
	thinking: { animation: 'idle', emotion: 'neutral', sound: 'thinking', duration: null },
	'chat-message': { animation: 'talk', emotion: 'neutral', sound: 'talk', duration: 1500 },
	'error-repeated': { animation: 'talk', emotion: 'sad', sound: null, duration: 2000, cooldown: 'pool' },
	milestone: { animation: 'celebrate', emotion: 'celebrate', sound: 'success', duration: 4000 },
	'idle-timeout': { animation: 'idle', emotion: 'sleep', sound: null, duration: null },
}

const DAY_MS = 86400000

const _cooldowns = new Map()

function isCoolingDown(eventType, context) {
	const entry = EVENT_MAP[eventType]
	if (!entry || !entry.cooldown) return false

	const key = entry.cooldown === 'pool'
		? eventType + ':' + (context.poolId || 'default')
		: eventType

	const cd = _cooldowns.get(key)
	if (!cd) return false

	if (entry.cooldown === 'session') return true
	if (entry.cooldown === 'day') return (Date.now() - cd.lastFired) < DAY_MS
	if (entry.cooldown === 'pool') return true

	return false
}

function setCooldown(eventType, context) {
	const entry = EVENT_MAP[eventType]
	if (!entry || !entry.cooldown) return

	const key = entry.cooldown === 'pool'
		? eventType + ':' + (context.poolId || 'default')
		: eventType

	_cooldowns.set(key, { lastFired: Date.now() })
}

export const novaReactions = {
	react(eventType, context = {}) {
		const entry = EVENT_MAP[eventType]
		if (!entry) return null
		if (isCoolingDown(eventType, context)) return null

		if (entry.sound) {
			novaAudio.play(entry.sound)
		}

		setCooldown(eventType, context)

		return {
			animation: entry.animation,
			emotion: entry.emotion,
			sound: entry.sound,
			duration: entry.duration,
		}
	},

	canReact(eventType) {
		return !isCoolingDown(eventType, {})
	},

	reset() {
		_cooldowns.clear()
	},
}
