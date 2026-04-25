/**
 * Generic character reaction engine.
 * Events resolve to animation, emotion, sound and duration with graceful
 * fallback when the active skin does not support the requested animation.
 *
 * Cooldowns live here so every skin shares the same reaction budget.
 * Sound playback stays in skin-specific wrappers.
 */

export const EVENT_MAP = {
	'answer-correct': { animation: 'talk', emotion: 'happy', sound: 'success', duration: 2000 },
	'answer-wrong': { animation: 'talk', emotion: 'sad', sound: 'error', duration: 2000 },
	'streak-5': {
		animation: 'celebrate',
		emotion: 'celebrate',
		sound: 'success',
		duration: 3000,
		cooldown: 'session',
	},
	'streak-lost': {
		animation: 'talk',
		emotion: 'sad',
		sound: null,
		duration: 2000,
		cooldown: 'session',
	},
	'badge-earned': {
		animation: 'celebrate',
		emotion: 'celebrate',
		sound: 'success',
		duration: 4000,
	},
	thinking: { animation: 'idle', emotion: 'neutral', sound: 'thinking', duration: null },
	'chat-message': { animation: 'talk', emotion: 'neutral', sound: 'talk', duration: 1500 },
	'error-repeated': {
		animation: 'talk',
		emotion: 'sad',
		sound: null,
		duration: 2000,
		cooldown: 'pool',
	},
	milestone: {
		animation: 'celebrate',
		emotion: 'celebrate',
		sound: 'success',
		duration: 4000,
	},
	'idle-timeout': { animation: 'idle', emotion: 'sleep', sound: null, duration: null },
}

const DAY_MS = 86400000
const cooldowns = new Map()

function cooldownKey(entry, event, context = {}) {
	if (entry.cooldown === 'pool') {
		return `${event}:${context.poolId || 'default'}`
	}

	return event
}

function isCoolingDown(event, context = {}) {
	const entry = EVENT_MAP[event]
	if (!entry?.cooldown) {
		return false
	}

	const cooldown = cooldowns.get(cooldownKey(entry, event, context))
	if (!cooldown) {
		return false
	}

	if (entry.cooldown === 'day') {
		return Date.now() - cooldown.lastFired < DAY_MS
	}

	return entry.cooldown === 'session' || entry.cooldown === 'pool'
}

function setCooldown(event, context = {}) {
	const entry = EVENT_MAP[event]
	if (!entry?.cooldown) {
		return
	}

	cooldowns.set(cooldownKey(entry, event, context), { lastFired: Date.now() })
}

export function resolveReaction(event, supportedStates = []) {
	const entry = EVENT_MAP[event]
	if (!entry) {
		return null
	}

	const supported = Array.isArray(supportedStates) ? supportedStates : []
	const animation = supported.includes(entry.animation) ? entry.animation : 'idle'

	return {
		animation,
		emotion: entry.emotion,
		sound: entry.sound,
		duration: entry.duration,
	}
}

export const characterReactions = {
	react(event, supportedStates, context = {}) {
		if (isCoolingDown(event, context)) {
			return null
		}

		const reaction = resolveReaction(event, supportedStates)
		if (!reaction) {
			return null
		}

		setCooldown(event, context)
		return reaction
	},

	canReact(event, context = {}) {
		return !!EVENT_MAP[event] && !isCoolingDown(event, context)
	},

	reset() {
		cooldowns.clear()
	},
}
