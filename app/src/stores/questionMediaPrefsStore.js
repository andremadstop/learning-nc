import { defineStore, getActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * GitHub #66: the two switches of the question player, per user on the server
 * (PUT /api/settings/personal/question-media, read with GET /api/settings/personal).
 *
 * - autoplay: the intro starts when a question is shown; after answering the spoken explanation
 *   of the chosen option and the dialog follow. Off by default (WCAG 1.4.2).
 * - sounds: 'auto' | 'yes' | 'no' — the short tone after answering. 'auto' follows autoplay
 *   until the learner makes a choice of their own.
 *
 * App.vue hydrates it from the personal settings it loads anyway, so the player adds no request.
 */
export const SOUNDS_VALUES = ['auto', 'yes', 'no']

/** Whether the answer tones play for these preferences. */
export function soundsOn(prefs) {
	if (!prefs) {
		return false
	}
	if (prefs.sounds === 'yes') {
		return true
	}
	if (prefs.sounds === 'no') {
		return false
	}
	return !!prefs.autoplay
}

/**
 * Store a change on `prefs` (the store, or a plain reactive object where no Pinia runs): the
 * switch moves at once, a failed save puts it back (a switch that shows "on" while the server
 * keeps "off" would flip back on the next page load). Resolves to true when it was saved.
 *
 * @param {{autoplay: boolean, sounds: string}} prefs
 * @param {{autoplay?: boolean, sounds?: string}} change
 */
export async function saveQuestionMediaPrefs(prefs, change) {
	const before = { autoplay: prefs.autoplay, sounds: prefs.sounds }
	const body = {}
	if (typeof change.autoplay === 'boolean') {
		prefs.autoplay = change.autoplay
		body.autoplay = change.autoplay
	}
	if (SOUNDS_VALUES.includes(change.sounds)) {
		prefs.sounds = change.sounds
		body.sounds = change.sounds
	}
	if (Object.keys(body).length === 0) {
		return true
	}
	try {
		const response = await axios.put(generateUrl('/apps/learning/api/settings/personal/question-media'), body)
		const data = response?.data
		if (data && typeof data.question_media_autoplay === 'string') {
			prefs.autoplay = data.question_media_autoplay === 'yes'
		}
		if (data && SOUNDS_VALUES.includes(data.answer_sounds)) {
			prefs.sounds = data.answer_sounds
		}
		return true
	} catch (e) {
		prefs.autoplay = before.autoplay
		prefs.sounds = before.sounds
		return false
	}
}

export const useQuestionMediaPrefsStore = defineStore('questionMediaPrefs', {
	state: () => ({
		autoplay: false,
		sounds: 'auto',
	}),
	getters: {
		soundsEnabled: (state) => soundsOn(state),
	},
	actions: {
		/** @param {{question_media_autoplay?: string, answer_sounds?: string}|null|undefined} payload */
		loadFromServerPayload(payload) {
			if (!payload || typeof payload !== 'object') {
				return
			}
			if (typeof payload.question_media_autoplay === 'string') {
				this.autoplay = payload.question_media_autoplay === 'yes'
			}
			if (SOUNDS_VALUES.includes(payload.answer_sounds)) {
				this.sounds = payload.answer_sounds
			}
		},
		/** Store a change (see saveQuestionMediaPrefs). */
		save(change) {
			return saveQuestionMediaPrefs(this, change)
		},
	},
})

/** The store when a Pinia instance is active, otherwise null (component tests mount without one). */
export function useOptionalQuestionMediaPrefsStore() {
	const pinia = getActivePinia()
	return pinia ? useQuestionMediaPrefsStore(pinia) : null
}
