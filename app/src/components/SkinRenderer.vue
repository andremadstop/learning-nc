<template>
	<button
		type="button"
		class="virtuprof-rail"
		:class="{ 'has-invite': inviteCount > 0 }"
		:aria-expanded="expanded ? 'true' : 'false'"
		@click="$emit('click')">
		<span class="virtuprof-rail-copy">
			<span class="virtuprof-rail-kicker">{{ vt('VirtuProf') }}</span>
			<span class="virtuprof-rail-title">{{ vt('Learning assistant') }}</span>
			<span class="virtuprof-rail-status">{{ statusText }}</span>
		</span>

		<span class="virtuprof-rail-avatar">
			<NovaAvatar
				v-if="resolvedSkinId === 'nova'"
				:animation="animation"
				:emotion="emotion"
				:has-message="hasMessage"
				:invite-count="inviteCount" />
			<ProfLernAvatar
				v-else-if="resolvedSkinId === 'prof_lern_classic'"
				:animation="animation"
				:has-message="hasMessage"
				:invite-count="inviteCount" />
			<CharacterAvatar
				v-else
				:key="resolvedSkinId"
				:character-id="resolvedSkinId"
				:state="animation"
				:size="64" />

			<span v-if="showExternalBadge" class="virtuprof-rail-badge">
				{{ inviteCount > 9 ? '9+' : inviteCount }}
			</span>
		</span>
	</button>
</template>

<script>
import CharacterAvatar from './CharacterAvatar.vue'
import ProfLernAvatar from './ProfLernAvatar.vue'
import NovaAvatar from './nova/NovaAvatar.vue'
import { resolveVirtuProfSkinId } from '../data/characters.js'
import { translateVirtuProf } from '../utils/virtuprof-i18n.js'

export default {
	name: 'SkinRenderer',
	components: {
		CharacterAvatar,
		NovaAvatar,
		ProfLernAvatar,
	},
	emits: ['click'],
	props: {
		skinId: { type: String, default: 'nova' },
		animation: { type: String, default: 'idle' },
		emotion: { type: String, default: null },
		hasMessage: { type: Boolean, default: false },
		inviteCount: { type: Number, default: 0 },
		statusText: { type: String, default: '' },
		expanded: { type: Boolean, default: false },
		language: { type: String, default: 'de' },
	},
	computed: {
		resolvedSkinId() {
			return resolveVirtuProfSkinId(this.skinId)
		},
		showExternalBadge() {
			return this.inviteCount > 0 && !['nova', 'prof_lern_classic'].includes(this.resolvedSkinId)
		},
	},
	methods: {
		vt(key, params) {
			return translateVirtuProf(this.language, key, params)
		},
	},
}
</script>

<style scoped>
.virtuprof-rail {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	padding: 10px 14px;
	border: none;
	border-radius: 16px;
	background: var(--color-background-dark, #1a1a2e);
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
	transition: background 0.2s ease, box-shadow 0.2s ease;
}

.virtuprof-rail:hover {
	background: var(--color-background-hover);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.virtuprof-rail.has-invite {
	box-shadow: 0 0 0 2px var(--nova-warning-rose, #f43f5e);
}

.virtuprof-rail-copy {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.virtuprof-rail-kicker {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--nova-accent-cyan, #06b6d4);
}

.virtuprof-rail-title {
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.virtuprof-rail-status {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.virtuprof-rail-avatar {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 64px;
}

.virtuprof-rail-badge {
	position: absolute;
	top: -4px;
	right: -4px;
	min-width: 18px;
	height: 18px;
	border-radius: 9px;
	background: var(--nova-warning-rose, #f43f5e);
	color: #fff;
	font-size: 11px;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0 4px;
	line-height: 1;
}
</style>
