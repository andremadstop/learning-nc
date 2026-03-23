<template>
	<div class="campaign-card"
		:class="cardClasses"
		:style="cardStyle"
		role="button"
		tabindex="0"
		@click="$emit('select', campaign)"
		@keydown.enter="$emit('select', campaign)">
		<div class="campaign-card__content">
			<div class="campaign-card__left">
				<span class="campaign-card__icon">{{ campaign.icon }}</span>
				<h3 class="campaign-card__title">{{ campaign.title }}</h3>
				<p class="campaign-card__description">{{ campaign.description }}</p>
			</div>
			<div class="campaign-card__right">
				<CharacterAvatar :characterId="mainNpcId" :size="72" state="idle" />
			</div>
		</div>
		<div class="campaign-card__footer">
			<span class="campaign-card__badge" :class="'campaign-card__badge--' + campaign.difficulty">
				{{ difficultyLabel }}
			</span>
			<span v-for="area in campaign.focus_areas"
				:key="area"
				class="campaign-card__tag">
				{{ area }}
			</span>
		</div>
		<div v-if="campaign.progress === 'completed'" class="campaign-card__check-overlay" aria-hidden="true">
			&#10003;
		</div>
	</div>
</template>

<script>
import CharacterAvatar from './CharacterAvatar.vue'
import { getCharacter } from '../data/characters.js'

const DIFFICULTY_LABELS = {
	beginner: 'Einsteiger',
	intermediate: 'Fortgeschritten',
	advanced: 'Experte',
}

export default {
	name: 'CampaignCard',

	components: {
		CharacterAvatar,
	},

	props: {
		campaign: {
			type: Object,
			required: true,
		},
		mainNpcId: {
			type: String,
			default: 'nova',
		},
	},

	computed: {
		character() {
			return getCharacter(this.mainNpcId)
		},

		difficultyLabel() {
			return DIFFICULTY_LABELS[this.campaign.difficulty] || this.campaign.difficulty
		},

		cardClasses() {
			return {
				'campaign-card--completed': this.campaign.progress === 'completed',
			}
		},

		cardStyle() {
			return {
				background: `linear-gradient(135deg, #0d1117 0%, #161b22 60%, ${this.character.palette.glow} 100%)`,
			}
		},
	},
}
</script>

<style scoped>
.campaign-card {
	position: relative;
	border-radius: var(--lnc-radius-md);
	padding: 16px;
	cursor: pointer;
	border: 1px solid var(--lnc-border);
	transition: transform 200ms ease, box-shadow 200ms ease;
	overflow: hidden;
}

.campaign-card:hover,
.campaign-card:focus-visible {
	transform: translateY(-2px);
	box-shadow: 0 0 12px 2px var(--lnc-cyan);
	outline: none;
}

.campaign-card__content {
	display: flex;
	align-items: flex-start;
	gap: 12px;
}

.campaign-card__left {
	flex: 1;
	min-width: 0;
}

.campaign-card__icon {
	font-size: 1.6rem;
	display: block;
	margin-bottom: 6px;
}

.campaign-card__title {
	font-size: 1rem;
	font-weight: 700;
	color: var(--lnc-text);
	margin: 0 0 4px 0;
}

.campaign-card__description {
	font-size: 0.82rem;
	color: var(--lnc-text-secondary);
	margin: 0;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.campaign-card__right {
	flex-shrink: 0;
}

.campaign-card__footer {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 12px;
}

.campaign-card__badge {
	font-size: 0.72rem;
	font-weight: 600;
	padding: 2px 10px;
	border-radius: 99px;
	color: #0d1117;
}

.campaign-card__badge--beginner {
	background: var(--lnc-green);
}

.campaign-card__badge--intermediate {
	background: var(--lnc-amber);
}

.campaign-card__badge--advanced {
	background: var(--lnc-danger);
}

.campaign-card__tag {
	font-size: 0.7rem;
	padding: 2px 8px;
	border-radius: 99px;
	background: var(--lnc-panel-alt);
	color: var(--lnc-text-secondary);
}

/* ── Completed state ── */
.campaign-card--completed {
	opacity: 0.7;
}

.campaign-card__check-overlay {
	position: absolute;
	top: 8px;
	right: 8px;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--lnc-green);
	color: #0d1117;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 1rem;
	font-weight: 700;
}

/* ── Accessibility: reduce motion ── */
@media (prefers-reduced-motion: reduce) {
	.campaign-card {
		transition: none !important;
	}
	.campaign-card:hover,
	.campaign-card:focus-visible {
		transform: none;
	}
}
</style>
