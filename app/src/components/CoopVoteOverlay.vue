<template>
	<div class="coop-vote-overlay" role="dialog" aria-modal="true" :aria-label="t('learning', 'Abstimmung')">
		<div class="coop-vote-overlay__card">
			<div class="coop-vote-overlay__header">
				<div>
					<p class="coop-vote-overlay__eyebrow">{{ t('learning', 'Teamentscheidung') }}</p>
					<h3 class="coop-vote-overlay__title">{{ t('learning', 'Wohin geht es als Nächstes?') }}</h3>
				</div>
				<div class="coop-vote-overlay__meta">
					<span class="coop-vote-overlay__count">
						{{ voteDisplay.votesCast }} / {{ voteDisplay.totalPlayers }}
					</span>
					<span class="coop-vote-overlay__timer">
						{{ t('learning', 'Sync in {n}s', { n: countdownSeconds }) }}
					</span>
				</div>
			</div>

			<div class="coop-vote-overlay__choices">
				<button
					v-for="choice in voteDisplay.byChoice"
					:key="choice.choiceId"
					class="coop-vote-overlay__choice"
					:class="{
						'coop-vote-overlay__choice--selected': choice.choiceId === selectedChoiceId,
						'coop-vote-overlay__choice--leading': choice.isLeading,
					}"
					:disabled="disabled"
					@click="$emit('vote', choice.choiceId)">
					<div class="coop-vote-overlay__choice-top">
						<span class="coop-vote-overlay__choice-label">{{ choice.label }}</span>
						<span class="coop-vote-overlay__choice-count">{{ choice.votes }}</span>
					</div>
					<div class="coop-vote-overlay__bar">
						<div
							class="coop-vote-overlay__bar-fill"
							:style="{ width: choiceWidth(choice) }" />
					</div>
					<div class="coop-vote-overlay__avatars">
						<span
							v-for="player in playersForChoice(choice.choiceId)"
							:key="player.user_id"
							class="coop-vote-overlay__avatar"
							:title="player.display_name || player.user_id">
							{{ playerInitial(player) }}
						</span>
						<span v-if="choice.hasMajority" class="coop-vote-overlay__badge">
							{{ t('learning', 'Mehrheit') }}
						</span>
					</div>
				</button>
			</div>

			<div class="coop-vote-overlay__footer">
				<div v-if="voteDisplay.waitingPlayers.length" class="coop-vote-overlay__waiting">
					<span class="coop-vote-overlay__waiting-label">{{ t('learning', 'Warten auf') }}</span>
					<span
						v-for="player in voteDisplay.waitingPlayers"
						:key="player.user_id"
						class="coop-vote-overlay__waiting-player">
						{{ player.display_name || player.user_id }}
					</span>
				</div>
				<p v-else class="coop-vote-overlay__resolved">
					{{ t('learning', 'Alle Stimmen sind eingegangen. Die Szene wird synchronisiert ...') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import { computeVoteDisplay } from '../utils/coopEngine.js'

export default {
	name: 'CoopVoteOverlay',

	props: {
		choices: {
			type: Array,
			default: function() {
				return []
			},
		},
		votes: {
			type: Object,
			default: function() {
				return {}
			},
		},
		players: {
			type: Array,
			default: function() {
				return []
			},
		},
		selectedChoiceId: {
			type: String,
			default: '',
		},
		pollIntervalMs: {
			type: Number,
			default: 3000,
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['vote'],

	data() {
		return {
			countdownSeconds: Math.max(1, Math.ceil(this.pollIntervalMs / 1000)),
			countdownTimer: null,
		}
	},

	computed: {
		voteDisplay() {
			return computeVoteDisplay(this.votes, this.players, this.choices)
		},
	},

	watch: {
		pollIntervalMs: {
			immediate: true,
			handler() {
				this.restartCountdown()
			},
		},
		votes: {
			deep: true,
			handler() {
				this.restartCountdown()
			},
		},
	},

	beforeDestroy() {
		if (this.countdownTimer) {
			clearInterval(this.countdownTimer)
			this.countdownTimer = null
		}
	},

	methods: {
		restartCountdown() {
			if (this.countdownTimer) {
				clearInterval(this.countdownTimer)
			}
			this.countdownSeconds = Math.max(1, Math.ceil(this.pollIntervalMs / 1000))
			this.countdownTimer = setInterval(() => {
				if (this.countdownSeconds <= 1) {
					this.countdownSeconds = Math.max(1, Math.ceil(this.pollIntervalMs / 1000))
					return
				}
				this.countdownSeconds -= 1
			}, 1000)
		},

		choiceWidth(choice) {
			if (!this.voteDisplay.totalPlayers) {
				return '0%'
			}
			return `${Math.min(100, (choice.votes / this.voteDisplay.totalPlayers) * 100)}%`
		},

		playersForChoice(choiceId) {
			return this.players.filter(player => this.votes.player_votes?.[player.user_id] === choiceId)
		},

		playerInitial(player) {
			const name = String(player.display_name || player.user_id || '?').trim()
			return name.charAt(0).toUpperCase()
		},
	},
}
</script>
