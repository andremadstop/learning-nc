<template>
	<component
		:is="rendererComponent"
		:key="skinId"
		v-bind="forwardedProps"
		@click="$emit('click')" />
</template>

<script>
import NovaDock from './nova/NovaDock.vue'
import ProfLernAvatar from './ProfLernAvatar.vue'
import CharacterAvatar from './CharacterAvatar.vue'
import { useSkinStore } from '../stores/skinStore.js'

/**
 * Polymorphic VirtuProf skin dispatcher (Phase 151 Plan 05).
 *
 * Reads useSkinStore().skinId and renders the matching avatar component:
 * - 'nova'              → NovaDock (Phase 150 unchanged Nova specialised renderer)
 * - 'prof_lern_classic' → ProfLernAvatar (Phase 151 Plan 06 chibi avatar)
 * - any other valid id  → CharacterAvatar with character-id prop
 *
 * Invalid skinId is coerced upstream by skinStore.setSkin (PICK-05); SkinRenderer
 * trusts the store and falls through to NovaDock if no specific branch matches.
 *
 * `:key="skinId"` triggers full re-render on skin change (PICK-04 reactive remount)
 * — without it Vue would patch props into the new component shape and likely crash
 * because NovaDock/ProfLernAvatar use dock-style props while CharacterAvatar uses
 * characterId/state/size.
 */
export default {
	name: 'SkinRenderer',
	components: { NovaDock, ProfLernAvatar, CharacterAvatar },
	emits: ['click'],
	props: {
		animation: { type: String, default: 'idle' },
		emotion: { type: String, default: null },
		hasMessage: { type: Boolean, default: false },
		inviteCount: { type: Number, default: 0 },
		statusText: { type: String, default: '' },
		expanded: { type: Boolean, default: false },
	},
	computed: {
		skinId() {
			return useSkinStore().skinId
		},
		rendererComponent() {
			if (this.skinId === 'nova') return NovaDock
			if (this.skinId === 'prof_lern_classic') return ProfLernAvatar
			// Valid campaign character id (architect, security, ...) — store guarantees validity
			return CharacterAvatar
		},
		forwardedProps() {
			if (this.rendererComponent === CharacterAvatar) {
				return {
					characterId: this.skinId,
					state: this.animation,
					size: 64,
				}
			}
			// NovaDock + ProfLernAvatar share dock-style props
			return {
				animation: this.animation,
				emotion: this.emotion,
				hasMessage: this.hasMessage,
				inviteCount: this.inviteCount,
				statusText: this.statusText,
				expanded: this.expanded,
			}
		},
	},
}
</script>
