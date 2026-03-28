<template>
	<div class="mode-identity-banner">
		<div class="mode-identity-banner__mode">
			<span class="mode-identity-banner__icon">{{ modeConfig.icon }}</span>
			<span class="mode-identity-banner__label">{{ modeConfig.label }}</span>
		</div>

		<span class="mode-identity-banner__separator" />

		<div class="mode-identity-banner__mentor">
			<CharacterAvatar :character-id="mentorId"
				state="idle"
				:size="28" />
			<span class="mode-identity-banner__mentor-name">{{ mentorCharacter.name }}</span>
		</div>

		<div v-if="goal" class="mode-identity-banner__goal">
			{{ goal }}
		</div>
	</div>
</template>

<script>
import CharacterAvatar from './CharacterAvatar.vue'
import { getCharacter } from '../data/characters.js'

const MODE_MAP = {
	training: { icon: '\uD83D\uDCDA', label: 'Training' },
	leitner: { icon: '\uD83D\uDCE6', label: 'Leitner-System' },
	exam: { icon: '\uD83D\uDCDD', label: 'Prüfung' },
	duel: { icon: '\u2694\uFE0F', label: 'Duell' },
	gameshow: { icon: '\uD83C\uDFAA', label: 'Gameshow' },
	abenteuer: { icon: '\uD83D\uDDFA', label: 'Abenteuer' },
}
// Labels above are used as t() keys in modeConfig computed

const FALLBACK_MODE = { icon: '\uD83D\uDCD6', label: '' }

export default {
	name: 'ModeIdentityBanner',

	components: {
		CharacterAvatar,
	},

	props: {
		mode: {
			type: String,
			required: true,
		},
		mentorId: {
			type: String,
			default: 'nova',
		},
		goal: {
			type: String,
			default: '',
		},
	},

	computed: {
		modeConfig() {
			const config = MODE_MAP[this.mode]
			if (config) {
				return { ...config, label: this.t('learning', config.label) }
			}
			return { icon: FALLBACK_MODE.icon, label: this.mode }
		},

		mentorCharacter() {
			return getCharacter(this.mentorId)
		},
	},
}
</script>

<style scoped>
.mode-identity-banner {
	display: flex;
	flex-direction: row;
	align-items: center;
	gap: 12px;
	height: 40px;
	padding: 4px 16px;
	background: var(--lnc-panel);
	border-bottom: 1px solid var(--lnc-border);
	border-radius: var(--lnc-radius-sm);
	font-size: 0.85rem;
	color: var(--lnc-text);
}

.mode-identity-banner__mode {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.mode-identity-banner__icon {
	font-size: 1rem;
	line-height: 1;
}

.mode-identity-banner__label {
	font-weight: 600;
}

.mode-identity-banner__separator {
	width: 1px;
	height: 20px;
	background: var(--lnc-border);
	flex-shrink: 0;
}

.mode-identity-banner__mentor {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.mode-identity-banner__mentor-name {
	font-size: 0.8rem;
	color: var(--lnc-text-secondary);
}

.mode-identity-banner__goal {
	font-size: 0.8rem;
	color: var(--lnc-text-secondary);
	max-width: 50%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-left: auto;
}

/* Future-proofing: reduce motion safety */
@media (prefers-reduced-motion: reduce) {
	.mode-identity-banner,
	.mode-identity-banner * {
		transition: none !important;
	}
}
</style>
