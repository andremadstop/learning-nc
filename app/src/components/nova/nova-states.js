/**
 * NOVA State definitions and emotion mapping.
 * Source: CHARACTER_BIBLE.md Sections 6-9
 */

export const STATES = {
	idle: { cssClass: 'nova--idle', defaultEmotion: 'neutral' },
	thinking: { cssClass: 'nova--thinking', defaultEmotion: 'neutral' },
	talk: { cssClass: 'nova--talk', defaultEmotion: 'neutral' },
	celebrate: { cssClass: 'nova--celebrate', defaultEmotion: 'celebrate' },
	glitch: { cssClass: 'nova--glitch', defaultEmotion: 'surprised' },
	sleep: { cssClass: 'nova--sleep', defaultEmotion: 'sleep' },
}

export const EMOTIONS = {
	neutral: { eyeSymbol: 'relaxed-dash', color: null },
	happy: { eyeSymbol: 'up-arrows', color: 'var(--nova-emotion-happy)' },
	sad: { eyeSymbol: 'down-look', color: 'var(--nova-emotion-sad)' },
	surprised: { eyeSymbol: 'exclamation', color: 'var(--nova-emotion-surprised)' },
	sleep: { eyeSymbol: 'single-dash', color: null },
	celebrate: { eyeSymbol: 'star', color: 'var(--nova-emotion-celebrate)' },
}

const LEGACY_MAP = {
	idle: 'idle',
	talk: 'talk',
	wave: 'idle',
	celebrate: 'celebrate',
}

/**
 * Map legacy VirtuProfAvatar animation strings to NOVA states.
 * @param {string} animation - Legacy animation name
 * @returns {string} NOVA state key
 */
export function mapLegacyAnimation(animation) {
	return LEGACY_MAP[animation] || 'idle'
}
