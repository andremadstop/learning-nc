<template>
	<div v-if="!loading && !error && examTimestamp > 0" class="exam-countdown" :class="countdownClass">
		<span class="exam-countdown__icon">⏱</span>
		<div class="exam-countdown__digits">
			<div class="cd-unit">
				<span class="cd-num" :key="'d'+days">{{ pad(days) }}</span>
				<span class="cd-label">{{ t('learning', 'Tage') }}</span>
			</div>
			<span class="cd-sep">:</span>
			<div class="cd-unit">
				<span class="cd-num" :key="'h'+hours">{{ pad(hours) }}</span>
				<span class="cd-label">{{ t('learning', 'Std') }}</span>
			</div>
			<span class="cd-sep">:</span>
			<div class="cd-unit">
				<span class="cd-num" :key="'m'+minutes">{{ pad(minutes) }}</span>
				<span class="cd-label">{{ t('learning', 'Min') }}</span>
			</div>
			<span class="cd-sep">:</span>
			<div class="cd-unit">
				<span class="cd-num cd-num--sec" :key="'s'+seconds">{{ pad(seconds) }}</span>
				<span class="cd-label">{{ t('learning', 'Sek') }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
export default {
	name: 'ExamReadiness',

	props: {
		courseId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			examTimestamp: 0,
			now: Math.floor(Date.now() / 1000),
			ticker: null,
		}
	},

	computed: {
		remaining() { return Math.max(0, this.examTimestamp - this.now) },
		days() { return Math.floor(this.remaining / 86400) },
		hours() { return Math.floor((this.remaining % 86400) / 3600) },
		minutes() { return Math.floor((this.remaining % 3600) / 60) },
		seconds() { return this.remaining % 60 },
		countdownClass() {
			if (this.days < 7) return 'countdown--red'
			if (this.days <= 14) return 'countdown--yellow'
			return 'countdown--green'
		},
	},

	mounted() {
		this.loadReadiness()
		this.ticker = setInterval(() => { this.now = Math.floor(Date.now() / 1000) }, 1000)
	},

	beforeUnmount() {
		if (this.ticker) clearInterval(this.ticker)
	},

	methods: {
		pad(n) { return String(n).padStart(2, '0') },
		async loadReadiness() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(generateUrl('/apps/learning/api/readiness/{courseId}', {
					courseId: this.courseId,
				}))
				const data = response.data || {}
				const dateStr = data.exam_date || ''
				if (dateStr) {
					if (dateStr.includes('T')) {
						// "2026-04-10T10:00" — parse as local time
						const [datePart, timePart] = dateStr.split('T')
						const [y, m, d] = datePart.split('-').map(Number)
						const [h, min] = timePart.split(':').map(Number)
						this.examTimestamp = Math.floor(new Date(y, m - 1, d, h, min).getTime() / 1000)
					} else {
						// Legacy "2026-04-10" — local midnight
						const [y, m, d] = dateStr.split('-').map(Number)
						this.examTimestamp = Math.floor(new Date(y, m - 1, d).getTime() / 1000)
					}
				}
			} catch (e) {
				this.error = e?.response?.data?.error || ''
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.exam-countdown {
	display: inline-flex;
	align-items: center;
	gap: 10px;
	padding: 8px 18px;
	border-radius: 14px;
	margin-bottom: 12px;
}

.exam-countdown__icon { font-size: 1.1rem; }

.exam-countdown__digits {
	display: flex;
	align-items: center;
	gap: 2px;
}

.cd-unit {
	display: flex;
	flex-direction: column;
	align-items: center;
	min-width: 36px;
}

.cd-num {
	font-family: 'Courier New', monospace;
	font-size: 1.4rem;
	font-weight: 800;
	line-height: 1;
	display: inline-block;
	animation: cd-tick 0.3s ease-out;
}

.cd-num--sec {
	animation: cd-tick 0.3s ease-out;
}

.cd-label {
	font-size: 0.6rem;
	font-weight: 500;
	opacity: 0.6;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.cd-sep {
	font-size: 1.2rem;
	font-weight: 700;
	opacity: 0.4;
	align-self: flex-start;
	margin-top: 2px;
}

@keyframes cd-tick {
	0% { transform: translateY(-4px); opacity: 0.3; }
	100% { transform: translateY(0); opacity: 1; }
}

.countdown--green {
	background: color-mix(in srgb, var(--color-success) 25%, transparent);
	color: #4ade80;
}

.countdown--yellow {
	background: color-mix(in srgb, var(--color-warning) 28%, transparent);
	color: #fbbf24;
}

.countdown--red {
	background: color-mix(in srgb, var(--color-error) 28%, transparent);
	color: #f87171;
}

@media (prefers-reduced-motion: reduce) {
	.cd-num, .cd-num--sec { animation: none; }
}
</style>
