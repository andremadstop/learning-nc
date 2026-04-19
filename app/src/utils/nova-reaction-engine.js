/**
 * NOVA Reaction Engine — backwards-compatible wrapper around the shared
 * character-reaction-engine.
 */

import { novaAudio } from './nova-audio-manager.js'
import { EVENT_MAP, characterReactions } from './character-reaction-engine.js'

const NOVA_STATES = ['idle', 'talk', 'celebrate']

export const novaReactions = {
	react(eventType, context = {}) {
		const reaction = characterReactions.react(eventType, NOVA_STATES, context)
		if (!reaction) {
			return null
		}

		if (reaction.sound) {
			novaAudio.play(reaction.sound)
		}

		return reaction
	},

	canReact(eventType, context = {}) {
		return EVENT_MAP[eventType] ? characterReactions.canReact(eventType, context) : false
	},

	reset() {
		characterReactions.reset()
	},
}
