<template>
	<div
		class="game-timer"
		:class="timerClasses"
		role="timer"
		:aria-label="timerAriaLabel">
		<div
			class="game-timer-bar"
			:style="timerBarStyle" />
		<div class="game-timer-content">
			<span class="game-timer-label">{{ timerLabel }}</span>
			<span class="game-timer-time" aria-live="polite">{{ timerState.formattedTime }}</span>
		</div>
		<div v-if="showExpiredOverlay" class="game-timer-expired" aria-live="assertive">
			{{ t('learning', 'Zeit abgelaufen!') }}
		</div>
	</div>
</template>

<script>
import { computeTimerState } from '../utils/timerEngine.js'

export default {
	name: 'GameTimer',

	props: {
		deadlineAt: {
			type: [Number, String],
			default: 0,
		},
		totalSeconds: {
			type: Number,
			default: 0,
		},
		reducedMotion: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			timerState: computeTimerState(this.deadlineAt, this.totalSeconds),
			showExpiredOverlay: false,
		}
	},

	computed: {
		timerClasses() {
			return [
				`game-timer--${this.timerState.phase}`,
				{
					'game-timer--urgent': this.timerState.lastTenSeconds,
					'game-timer--expired': this.timerState.expired,
					'game-timer--reduced': this.reducedMotion,
				},
			]
		},

		timerBarStyle() {
			return {
				width: `${this.timerState.fraction * 100}%`,
				backgroundColor: this.timerState.color,
			}
		},

		timerAriaLabel() {
			if (this.timerState.expired) {
				return t('learning', 'Exam time expired')
			}
			return t('learning', 'Exam time: {time} remaining, {phase}', {
				time: this.timerState.formattedTime,
				phase: this.timerPhaseLabel,
			})
		},

		timerPhaseLabel() {
			if (this.timerState.expired) {
				return t('learning', 'Expired')
			}
			if (this.timerState.phase === 'warning') {
				return t('learning', 'Warning')
			}
			if (this.timerState.phase === 'danger') {
				return t('learning', 'Critical')
			}
			return t('learning', 'Safe')
		},

		timerLabel() {
			return this.timerPhaseLabel
		},
	},

	watch: {
		deadlineAt() {
			this.restartTimer()
		},
		totalSeconds() {
			this.restartTimer()
		},
	},

	mounted() {
		this._expiredEmitted = false
		this._expiredOverlayTimer = null
		this._onVisibilityChange = () => {
			if (document.visibilityState === 'visible') {
				this.updateTimerState()
			}
		}
		document.addEventListener('visibilitychange', this._onVisibilityChange)
		this.restartTimer()
	},

	beforeDestroy() {
		if (this._timerInterval) {
			clearInterval(this._timerInterval)
			this._timerInterval = null
		}
		if (this._expiredOverlayTimer) {
			clearTimeout(this._expiredOverlayTimer)
			this._expiredOverlayTimer = null
		}
		if (this._onVisibilityChange) {
			document.removeEventListener('visibilitychange', this._onVisibilityChange)
			this._onVisibilityChange = null
		}
	},

	methods: {
		restartTimer() {
			if (this._timerInterval) {
				clearInterval(this._timerInterval)
			}
			if (this._expiredOverlayTimer) {
				clearTimeout(this._expiredOverlayTimer)
				this._expiredOverlayTimer = null
			}
			this._expiredEmitted = false
			this.showExpiredOverlay = false
			this.updateTimerState()
			if (!this.deadlineAt || !this.totalSeconds) {
				return
			}
			this._timerInterval = setInterval(() => {
				this.updateTimerState()
			}, 1000)
		},

		updateTimerState() {
			this.timerState = computeTimerState(this.deadlineAt, this.totalSeconds)
			if (this.timerState.expired && !this._expiredEmitted) {
				this._expiredEmitted = true
				this.showExpiredOverlay = true
				this.$emit('expired')
				this._expiredOverlayTimer = setTimeout(() => {
					this.showExpiredOverlay = false
					this._expiredOverlayTimer = null
				}, 2000)
			}
		},
	},
}
</script>
