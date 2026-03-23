<template>
	<div class="dialogue-stage"
		:class="{ 'dialogue-stage--narrator': isNarrator }">
		<!-- Narrator mode: full-width narrative box -->
		<template v-if="isNarrator">
			<div class="dialogue-stage__narrative">
				<p class="dialogue-stage__narrative-text">{{ text }}</p>
			</div>
		</template>

		<!-- Dialog mode: portrait + speech bubble -->
		<template v-else>
			<div class="dialogue-stage__portrait">
				<CharacterAvatar
					:characterId="speakerId"
					:size="64"
					:state="emotion" />
				<span class="dialogue-stage__emotion-tag"
					:style="emotionTagStyle">
					{{ emotionLabel }}
				</span>
			</div>
			<div class="dialogue-stage__speech">
				<div class="dialogue-stage__name" :style="nameStyle">
					{{ speakerName }}
				</div>
				<div class="dialogue-stage__bubble" :style="bubbleStyle">
					<p class="dialogue-stage__text">{{ text }}</p>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import CharacterAvatar from './CharacterAvatar.vue'
import { getCharacter } from '../data/characters.js'

const EMOTION_LABELS = {
	idle: 'ruhig',
	thinking: 'nachdenklich',
	explain: 'erklaerend',
	alert: 'wachsam',
	celebrate: 'begeistert',
	confused: 'verwirrt',
	frustrated: 'frustriert',
	relieved: 'erleichtert',
	impressed: 'beeindruckt',
}

export default {
	name: 'DialogueStage',

	components: {
		CharacterAvatar,
	},

	props: {
		speakerId: {
			type: String,
			default: null,
		},
		speakerName: {
			type: String,
			default: '',
		},
		text: {
			type: String,
			required: true,
		},
		emotion: {
			type: String,
			default: 'idle',
		},
		isNarrator: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		character() {
			if (!this.speakerId) {
				return null
			}
			return getCharacter(this.speakerId)
		},

		emotionLabel() {
			return EMOTION_LABELS[this.emotion] || this.emotion
		},

		emotionTagStyle() {
			if (!this.character) {
				return {}
			}
			return {
				background: this.character.palette.accent + '33',
				color: this.character.palette.accent,
			}
		},

		nameStyle() {
			if (!this.character) {
				return {}
			}
			return {
				color: this.character.palette.primary,
			}
		},

		bubbleStyle() {
			if (!this.character) {
				return {}
			}
			return {
				borderLeftColor: this.character.palette.accent,
			}
		},
	},
}
</script>

<style scoped>
.dialogue-stage {
	display: flex;
	flex-direction: row;
	gap: 12px;
	align-items: flex-start;
}

/* ── Portrait column ── */
.dialogue-stage__portrait {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 80px;
	flex-shrink: 0;
	gap: 6px;
}

.dialogue-stage__emotion-tag {
	font-size: 0.7rem;
	border-radius: 99px;
	padding: 2px 8px;
	white-space: nowrap;
}

/* ── Speech column ── */
.dialogue-stage__speech {
	flex: 1;
	min-width: 0;
}

.dialogue-stage__name {
	font-weight: 700;
	font-size: 0.85rem;
	margin-bottom: 4px;
}

.dialogue-stage__bubble {
	background: var(--lnc-panel);
	border-radius: var(--lnc-radius-sm);
	padding: 12px 16px;
	border-left: 3px solid var(--lnc-border);
	transition: border-left-color 200ms ease;
}

.dialogue-stage__text {
	margin: 0;
	color: var(--lnc-text);
	font-size: 0.9rem;
	line-height: 1.5;
}

/* ── Narrator mode ── */
.dialogue-stage--narrator {
	display: block;
}

.dialogue-stage__narrative {
	background: #0a0e15;
	border-radius: var(--lnc-radius-sm);
	padding: 14px 18px;
	border-left: 3px solid var(--lnc-amber);
}

.dialogue-stage__narrative-text {
	margin: 0;
	color: var(--lnc-text-secondary);
	font-style: italic;
	font-size: 0.9rem;
	line-height: 1.6;
}

/* ── Accessibility: reduce motion ── */
@media (prefers-reduced-motion: reduce) {
	.dialogue-stage__bubble {
		transition: none !important;
	}
}
</style>
