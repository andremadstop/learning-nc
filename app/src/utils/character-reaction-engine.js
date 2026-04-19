/**
 * Shared reaction engine for all character skins.
 *
 * Sound side-effects intentionally stay in skin-specific wrappers.
 */

export const EVENT_MAP = {
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
const cooldowns = new Map()

function normalizeSupportedStates(supportedStates = []) {
	if (!Array.isArray(supportedStates) || supportedStates.length === 0) {
		return ['idle']
	}

	if (supportedStates.includes('idle')) {
		return supportedStates
	}

	return ['idle', ...supportedStates]
}

function resolveAnimation(animation, supportedStates = []) {
	return normalizeSupportedStates(supportedStates).includes(animation) ? animation : 'idle'
}

function cooldownKey(eventType, cooldown, context = {}) {
	if (cooldown === 'pool') {
		return `${eventType}:${context.poolId || 'default'}`
	}

	return eventType
}

function isCoolingDown(eventType, context = {}) {
	const entry = EVENT_MAP[eventType]
	if (!entry?.cooldown) {
		return false
	}

	const key = cooldownKey(eventType, entry.cooldown, context)
	const cooldown = cooldowns.get(key)
	if (!cooldown) {
		return false
	}

	if (entry.cooldown === 'session' || entry.cooldown === 'pool') {
		return true
	}

	if (entry.cooldown === 'day') {
		return (Date.now() - cooldown.lastFired) < DAY_MS
	}

	return false
}

function setCooldown(eventType, context = {}) {
	const entry = EVENT_MAP[eventType]
	if (!entry?.cooldown) {
		return
	}

	const key = cooldownKey(eventType, entry.cooldown, context)
	cooldowns.set(key, { lastFired: Date.now() })
}

export function resolveReaction(eventType, supportedStates = []) {
	const entry = EVENT_MAP[eventType]
	if (!entry) {
		return null
	}

	return {
		animation: resolveAnimation(entry.animation, supportedStates),
		emotion: entry.emotion,
		sound: entry.sound,
		duration: entry.duration,
	}
}

export const characterReactions = {
	react(eventType, supportedStates = [], context = {}) {
		if (isCoolingDown(eventType, context)) {
			return null
		}

		const reaction = resolveReaction(eventType, supportedStates)
		if (!reaction) {
			return null
		}

		setCooldown(eventType, context)
		return reaction
	},

	canReact(eventType, context = {}) {
		return EVENT_MAP[eventType] ? !isCoolingDown(eventType, context) : false
	},

	reset() {
		cooldowns.clear()
	},
}
