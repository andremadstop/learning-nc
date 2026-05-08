<template>
	<VirtuProfDock
		:has-message="hasMessage"
		:invite-count="inviteCount"
		:status-text="statusText"
		:expanded="expanded"
		@click="$emit('click')">
		<component
			:is="avatarComponent"
			:key="skinId"
			v-bind="forwardedProps" />
	</VirtuProfDock>
</template>

<script>
import VirtuProfDock from './VirtuProfDock.vue'
import NovaAvatar from './nova/NovaAvatar.vue'
import ProfLernAvatar from './ProfLernAvatar.vue'
import TheoretikerAvatar from './TheoretikerAvatar.vue'
import KosmologeAvatar from './KosmologeAvatar.vue'
import PopularisiererAvatar from './PopularisiererAvatar.vue'
import CharacterAvatar from './CharacterAvatar.vue'
import { useSkinStore } from '../stores/skinStore.js'

const AVATAR_SIZE = 80

const CHIBI_SCHOLAR_COMPONENTS = {
	theoretiker: TheoretikerAvatar,
	kosmologe: KosmologeAvatar,
	popularisierer: PopularisiererAvatar,
}

/**
 * Polymorphic VirtuProf skin dispatcher.
 *
 * Reads useSkinStore().skinId, wraps the chosen avatar in VirtuProfDock so every
 * skin shares the same kicker/title/status pill layout. Avatar dispatch:
 *   - 'nova'              → NovaAvatar
 *   - 'prof_lern_classic' → ProfLernAvatar
 *   - any valid char id   → CharacterAvatar (characterId prop)
 *
 * Invalid ids are coerced upstream by skinStore.setSkin and fall through to
 * NovaAvatar via the dispatcher branch.
 *
 * `:key="skinId"` triggers full remount on skin change so each avatar mounts
 * with its own prop shape.
 */
export default {
	name: 'SkinRenderer',
	components: { VirtuProfDock, NovaAvatar, ProfLernAvatar, TheoretikerAvatar, KosmologeAvatar, PopularisiererAvatar, CharacterAvatar },
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
		avatarComponent() {
			if (this.skinId === 'nova') return NovaAvatar
			if (this.skinId === 'prof_lern_classic') return ProfLernAvatar
			if (CHIBI_SCHOLAR_COMPONENTS[this.skinId]) return CHIBI_SCHOLAR_COMPONENTS[this.skinId]
			return CharacterAvatar
		},
		forwardedProps() {
			if (this.avatarComponent === CharacterAvatar) {
				return {
					characterId: this.skinId,
					state: this.animation,
					size: AVATAR_SIZE,
				}
			}
			if (this.avatarComponent === NovaAvatar) {
				return {
					animation: this.animation,
					emotion: this.emotion,
					hasMessage: this.hasMessage,
					inviteCount: this.inviteCount,
					size: AVATAR_SIZE,
				}
			}
			// ProfLern + chibi scholars share the same prop shape
			return {
				animation: this.animation,
				hasMessage: this.hasMessage,
				inviteCount: this.inviteCount,
				size: AVATAR_SIZE,
			}
		},
	},
}
</script>
