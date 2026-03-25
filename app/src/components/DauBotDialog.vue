<template>
	<div class="daubot-dialog">
		<div class="daubot-dialog-header">
			<div class="daubot-dialog-avatar">
				<CharacterAvatar
					characterId="klaus_dau"
					:size="64"
					:state="avatarState" />
			</div>
			<div class="daubot-speech-bubble">
				<div class="daubot-speech-name">Klaus / DAU-Bot</div>
				<p class="daubot-speech-text">{{ scenario.bot_action }}</p>
				<p class="daubot-speech-symptom">{{ scenario.symptom }}</p>
			</div>
		</div>

		<div class="daubot-stage">
			<template v-if="session.phase === 'diagnose'">
				<h3 class="daubot-stage-title">{{ t('learning', 'Was ist passiert?') }}</h3>
				<div class="daubot-options">
					<button
						v-for="option in scenario.error_options || []"
						:key="option.id"
						class="daubot-option"
						:class="{ 'daubot-option--selected': session.selectedErrorId === option.id }"
						@click="handleDiagnosis(option.id)">
						{{ option.label }}
					</button>
				</div>
			</template>

			<template v-else-if="session.phase === 'fix'">
				<h3 class="daubot-stage-title">{{ t('learning', 'Wie fixen wir das?') }}</h3>
				<div class="daubot-options">
					<button
						v-for="option in scenario.fix_options || []"
						:key="option.id"
						class="daubot-option"
						:class="{ 'daubot-option--selected': session.selectedFixId === option.id }"
						@click="handleFix(option.id)">
						{{ option.label }}
					</button>
				</div>
			</template>

			<template v-else-if="session.phase === 'awaiting_result'">
				<div class="daubot-awaiting">
					<div class="ab-spinner"></div>
					<p class="daubot-awaiting-text">{{ t('learning', 'Klaus wartet gespannt...') }}</p>
				</div>
			</template>

			<template v-else-if="session.phase === 'summary'">
				<div
					class="daubot-summary"
					:class="summaryClass">
					<div class="daubot-summary-badge">{{ resultBadge }}</div>
					<p class="daubot-summary-reaction">{{ reactionText }}</p>
					<p class="daubot-summary-text">{{ summaryText }}</p>
					<button
						class="daubot-continue"
						@click="$emit('result-applied')">
						{{ t('learning', 'Weiter') }}
					</button>
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import CharacterAvatar from './CharacterAvatar.vue'
import {
	applyResult,
	computeBotSummary,
	createBotSession,
	getKlausAvatarState,
	getKlausReaction,
	submitDiagnosis,
	submitFix,
} from '../utils/dauBotEngine.js'

export default {
	name: 'DauBotDialog',

	components: {
		CharacterAvatar,
	},

	props: {
		scenario: {
			type: Object,
			default: function() {
				return {}
			},
		},
		reducedMotion: {
			type: Boolean,
			default: false,
		},
		result: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			session: createBotSession(this.scenario),
			loading: false,
		}
	},

	computed: {
		avatarState() {
			return getKlausAvatarState(this.session.phase, this.session.passed)
		},

		reactionText() {
			return getKlausReaction(this.session.phase, this.session.passed)
		},

		summaryText() {
			return computeBotSummary(this.session, this.result)
		},

		summaryClass() {
			return this.session.passed ? 'daubot-result-correct' : 'daubot-result-wrong'
		},

		resultBadge() {
			return this.session.passed ? '✓' : '✕'
		},
	},

	watch: {
		scenario: {
			deep: true,
			handler(nextScenario) {
				this.session = createBotSession(nextScenario)
				this.loading = false
			},
		},
		result: {
			deep: true,
			handler(nextResult) {
				if (!nextResult) {
					return
				}
				this.loading = false
				this.session = applyResult(this.session, nextResult)
			},
		},
	},

	methods: {
		handleDiagnosis(optionId) {
			this.session = submitDiagnosis(this.session, optionId)
		},

		handleFix(optionId) {
			this.loading = true
			this.session = submitFix(this.session, optionId)
			this.$emit('submit', {
				errorId: this.session.selectedErrorId,
				fixId: optionId,
			})
		},
	},
}
</script>
