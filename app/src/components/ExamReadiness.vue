<template>
	<section class="exam-readiness-card">
		<div class="exam-readiness-card__header">
			<div>
				<h4>{{ t('learning', 'Prüfungsbereitschaft') }}</h4>
				<p>{{ subtitle }}</p>
			</div>
			<div class="exam-readiness-card__ring" :style="ringStyle">
				<strong>{{ readinessPercent }}%</strong>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error" class="exam-readiness-card__message">
			{{ error }}
		</NcNoteCard>

		<div v-else-if="loading" class="exam-readiness-card__loading">
			<NcLoadingIcon :size="28" />
			<span>{{ t('learning', 'Berechne Prognose...') }}</span>
		</div>

		<template v-else>
			<div v-if="daysUntilExam > 0" class="exam-readiness-card__countdown" :class="countdownClass">
				<span class="countdown-number">{{ daysUntilExam }}</span>
				<span class="countdown-label">{{ t('learning', 'Tage bis zur Pruefung') }}</span>
			</div>

			<div class="exam-readiness-card__status-row">
				<span class="readiness-status" :class="'readiness-status--' + statusKey">
					{{ statusLabel }}
				</span>
				<span class="readiness-meta">
					{{ t('learning', '{n} Fragen im Kurskontext', { n: questionCount }) }}
				</span>
			</div>

			<div v-if="poolReadiness.length" class="exam-readiness-card__pools">
				<div
					v-for="pool in poolReadiness"
					:key="pool.pool_id"
					class="exam-readiness-pool">
					<div class="exam-readiness-pool__meta">
						<strong>{{ pool.pool_name }}</strong>
						<span>{{ pool.readiness_percent }}%</span>
					</div>
					<div class="exam-readiness-pool__bar">
						<div class="exam-readiness-pool__fill" :style="{ width: pool.readiness_percent + '%' }" />
					</div>
					<div class="exam-readiness-pool__detail">
						{{ t('learning', '{reviewed} gelernt / {total} relevant', {
							reviewed: pool.reviewed_count,
							total: pool.question_count,
						}) }}
					</div>
				</div>
			</div>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'ExamReadiness',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

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
			readinessPercent: 0,
			statusKey: 'needs_practice',
			daysUntilExam: 0,
			examDate: '',
			questionCount: 0,
			poolReadiness: [],
		}
	},

	computed: {
		subtitle() {
			if (!this.examDate) {
				return t('learning', 'Keine Prüfung konfiguriert.')
			}
			if (this.daysUntilExam <= 0) {
				return t('learning', 'Prognose für heute ({date})', { date: this.examDate })
			}
			return t('learning', 'Prognose bis {date} · noch {days} Tage', {
				date: this.examDate,
				days: this.daysUntilExam,
			})
		},
		statusLabel() {
			return this.statusKey === 'on_track'
				? t('learning', 'Auf Kurs')
				: t('learning', 'Mehr lernen')
		},
		countdownClass() {
			if (this.daysUntilExam < 7) return 'countdown--red'
			if (this.daysUntilExam <= 14) return 'countdown--yellow'
			return 'countdown--green'
		},
		ringStyle() {
			const percent = Math.max(0, Math.min(100, Number(this.readinessPercent || 0)))
			return {
				background: `conic-gradient(var(--color-success) ${percent}%, color-mix(in srgb, var(--color-main-background) 88%, var(--color-border) 12%) ${percent}% 100%)`,
			}
		},
	},

	mounted() {
		this.loadReadiness()
	},

	methods: {
		async loadReadiness() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(generateUrl('/apps/learning/api/readiness/{courseId}', {
					courseId: this.courseId,
				}))
				const data = response.data || {}
				this.readinessPercent = Number(data.readiness_percent || 0)
				this.statusKey = data.status || 'needs_practice'
				this.daysUntilExam = Number(data.days_until_exam || 0)
				this.examDate = data.exam_date || ''
				this.questionCount = Number(data.question_count || 0)
				this.poolReadiness = Array.isArray(data.pool_readiness) ? data.pool_readiness : []
			} catch (e) {
				this.error = e?.response?.data?.error || t('learning', 'Prüfungsbereitschaft konnte nicht geladen werden.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.exam-readiness-card {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	margin-bottom: 16px;
	border: 1px solid var(--color-border);
	border-radius: 20px;
	background:
		linear-gradient(135deg, color-mix(in srgb, var(--color-primary-element) 10%, transparent), transparent 60%),
		var(--color-main-background);
}

.exam-readiness-card__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
}

.exam-readiness-card__header h4 {
	margin: 0 0 6px 0;
}

.exam-readiness-card__header p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.exam-readiness-card__ring {
	position: relative;
	display: grid;
	place-items: center;
	width: 96px;
	height: 96px;
	border-radius: 50%;
	flex-shrink: 0;
}

.exam-readiness-card__ring::before {
	content: '';
	width: 70px;
	height: 70px;
	border-radius: 50%;
	background: var(--color-main-background);
}

.exam-readiness-card__ring strong {
	position: absolute;
	font-size: 20px;
	color: var(--color-main-text);
}

.exam-readiness-card__message {
	margin: 0;
}

.exam-readiness-card__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
}

.exam-readiness-card__status-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.readiness-status {
	display: inline-flex;
	align-items: center;
	padding: 6px 12px;
	border-radius: 999px;
	font-weight: 700;
}

.readiness-status--on_track {
	background: color-mix(in srgb, var(--color-success) 16%, transparent);
	color: var(--color-success);
}

.readiness-status--needs_practice {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.readiness-meta {
	color: var(--color-text-maxcontrast);
}

.exam-readiness-card__pools {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.exam-readiness-pool {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.exam-readiness-pool__meta,
.exam-readiness-pool__detail {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.exam-readiness-pool__detail {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.exam-readiness-pool__bar {
	height: 10px;
	border-radius: 999px;
	background: color-mix(in srgb, var(--color-main-background) 70%, var(--color-border) 30%);
	overflow: hidden;
}

.exam-readiness-pool__fill {
	height: 100%;
	border-radius: inherit;
	background: linear-gradient(90deg, var(--color-error), var(--color-warning), var(--color-success));
}

.exam-readiness-card__countdown {
	display: flex;
	align-items: baseline;
	gap: 8px;
	padding: 8px 14px;
	border-radius: 12px;
}

.countdown-number {
	font-size: 32px;
	font-weight: 800;
	line-height: 1;
}

.countdown-label {
	font-size: 14px;
	font-weight: 600;
}

.countdown--green {
	background: color-mix(in srgb, var(--color-success) 12%, transparent);
	color: var(--color-success);
}

.countdown--yellow {
	background: color-mix(in srgb, var(--color-warning) 14%, transparent);
	color: var(--color-warning);
}

.countdown--red {
	background: color-mix(in srgb, var(--color-error) 14%, transparent);
	color: var(--color-error);
}

@media (max-width: 720px) {
	.exam-readiness-card__header {
		align-items: flex-start;
	}

	.exam-readiness-card__ring {
		width: 84px;
		height: 84px;
	}

	.exam-readiness-card__ring::before {
		width: 60px;
		height: 60px;
	}
}
</style>
