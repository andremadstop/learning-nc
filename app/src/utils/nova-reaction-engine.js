/**
 * NOVA Reaction Engine — backwards-compatible wrapper around the generic
 * character reaction engine. The public novaReactions API is preserved for
 * VirtuProf.vue; Nova-specific audio playback remains here.
 */

import { characterReactions } from './character-reaction-engine.js'
import { novaAudio } from './nova-audio-manager.js'

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
		return characterReactions.canReact(eventType, context)
	},

	reset() {
		characterReactions.reset()
	},
}
