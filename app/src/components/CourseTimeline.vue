<template>
	<div class="course-timeline">
		<div v-if="loading" class="timeline-loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('learning', 'Zeitplan wird geladen...') }}</span>
		</div>

		<div v-else-if="!schedule.length" class="timeline-empty">
			<p>{{ t('learning', 'Kein Zeitplan vorhanden.') }}</p>
		</div>

		<template v-else>
			<!-- Desktop: horizontal scroll; Mobile: vertical -->
			<div class="timeline-track" ref="track">
				<div class="timeline-line" />
				<div
					v-for="(item, idx) in timelineItems"
					:key="item.id || idx"
					class="timeline-station"
					:class="stationClass(item)"
					:title="stationTooltip(item)">
					<div class="station-dot" />
					<div class="station-body">
						<span class="station-title">{{ item.chapter_title }}</span>
						<span class="station-date">{{ formatDate(item.target_date) }}</span>
						<div v-if="item.progress !== null" class="station-progress">
							<div class="progress-bar">
								<div class="progress-fill" :style="{ width: item.progress + '%' }" />
							</div>
							<span class="progress-pct">{{ item.progress }}%</span>
						</div>
					</div>
				</div>

				<!-- Exam station -->
				<div
					v-if="examDate"
					class="timeline-station station-exam"
					:title="t('learning', 'Pruefungstermin')">
					<div class="station-dot station-dot--exam" />
					<div class="station-body">
						<span class="station-title station-title--exam">{{ t('learning', 'Pruefung') }}</span>
						<span class="station-date">{{ formatDate(examDate) }}</span>
						<span v-if="examCountdown > 0" class="exam-countdown-label">
							{{ examCountdown }} {{ t('learning', 'Tage') }}
						</span>
						<span v-else-if="examCountdown === 0" class="exam-countdown-label exam-countdown--today">
							{{ t('learning', 'Heute!') }}
						</span>
					</div>
				</div>
			</div>

			<!-- Legend -->
			<div class="timeline-legend">
				<span class="legend-item">
					<span class="legend-dot legend-dot--green" />
					{{ t('learning', 'Abgeschlossen') }}
				</span>
				<span class="legend-item">
					<span class="legend-dot legend-dot--yellow" />
					{{ t('learning', 'In Arbeit') }}
				</span>
				<span class="legend-item">
					<span class="legend-dot legend-dot--red" />
					{{ t('learning', 'Offen') }}
				</span>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'CourseTimeline',

	components: {
		NcLoadingIcon,
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
			schedule: [],
			chaptersInScope: [],
			examDate: '',
			progressMap: {},
		}
	},

	computed: {
		timelineItems() {
			return this.schedule.map((entry) => {
				const progress = this.progressMap[entry.chapter_ref] ?? null
				return {
					...entry,
					progress,
				}
			})
		},
		examCountdown() {
			if (!this.examDate) return -1
			const now = new Date()
			now.setHours(0, 0, 0, 0)
			let exam
			if (this.examDate.includes('T')) {
				const [datePart, timePart] = this.examDate.split('T')
				const [y, m, d] = datePart.split('-').map(Number)
				const [h, min] = timePart.split(':').map(Number)
				exam = new Date(y, m - 1, d, h, min)
			} else {
				const [y, m, d] = this.examDate.split('-').map(Number)
				exam = new Date(y, m - 1, d)
			}
			exam.setHours(0, 0, 0, 0)
			return Math.ceil((exam - now) / 86400000)
		},
	},

	watch: {
		courseId: {
			immediate: true,
			handler(id) {
				if (id) this.loadTimeline()
			},
		},
	},

	methods: {
		async loadTimeline() {
			this.loading = true
			try {
				const [scheduleRes, progressRes, readinessRes] = await Promise.allSettled([
					axios.get(generateUrl('/apps/learning/api/courses/{courseId}/schedule', { courseId: this.courseId })),
					axios.get(generateUrl('/apps/learning/api/courses/{courseId}/my-progress', { courseId: this.courseId })),
					axios.get(generateUrl('/apps/learning/api/readiness/{courseId}', { courseId: this.courseId })),
				])

				if (scheduleRes.status === 'fulfilled') {
					const data = scheduleRes.value.data || {}
					this.schedule = data.schedule || []
					this.chaptersInScope = data.chapters_in_scope || []
				}

				if (progressRes.status === 'fulfilled') {
					const pools = Array.isArray(progressRes.value.data)
						? progressRes.value.data
						: (progressRes.value.data?.pools || [])
					const map = {}
					for (const p of pools) {
						const ref = p.chapter_ref || p.pool_name || ''
						if (ref && p.total_questions > 0) {
							map[ref] = Math.round((p.mastered || 0) / p.total_questions * 100)
						}
					}
					this.progressMap = map
				}

				if (readinessRes.status === 'fulfilled') {
					this.examDate = readinessRes.value.data?.exam_date || ''
				}
			} catch (e) {
				// silent — timeline is optional widget
			} finally {
				this.loading = false
			}
		},

		stationClass(item) {
			if (item.progress === null) return 'station--unknown'
			if (item.progress >= 80) return 'station--green'
			if (item.progress >= 30) return 'station--yellow'
			return 'station--red'
		},

		stationTooltip(item) {
			const parts = [item.chapter_title]
			if (item.target_date) {
				parts.push(this.formatDate(item.target_date))
			}
			if (item.progress !== null) {
				parts.push(item.progress + '%')
			}
			return parts.join(' — ')
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			try {
				const d = new Date(dateStr)
				return d.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit' })
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.course-timeline {
	width: 100%;
}

.timeline-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.timeline-empty {
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.timeline-empty p { margin: 0; }

/* Track: horizontal on desktop, vertical on mobile */
.timeline-track {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: 0;
	overflow-x: auto;
	padding: 16px 0 8px;
	scrollbar-width: thin;
}

.timeline-line {
	position: absolute;
	top: 30px;
	left: 0;
	right: 0;
	height: 3px;
	background: var(--color-border);
	z-index: 0;
}

/* Station */
.timeline-station {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	min-width: 110px;
	flex-shrink: 0;
	z-index: 1;
	padding: 0 8px;
}

.station-dot {
	width: 16px;
	height: 16px;
	border-radius: 50%;
	border: 3px solid var(--color-border);
	background: var(--color-main-background);
	margin-bottom: 8px;
	transition: border-color 0.2s, background 0.2s;
}

.station--green .station-dot {
	border-color: var(--color-success);
	background: var(--color-success);
}

.station--yellow .station-dot {
	border-color: var(--color-warning);
	background: var(--color-warning);
}

.station--red .station-dot {
	border-color: var(--color-error);
	background: color-mix(in srgb, var(--color-error) 30%, var(--color-main-background));
}

.station-dot--exam {
	width: 20px;
	height: 20px;
	border-color: var(--color-primary-element) !important;
	background: var(--color-primary-element) !important;
	box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-primary-element) 25%, transparent);
}

.station-body {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	gap: 2px;
}

.station-title {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-main-text);
	line-height: 1.2;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.station-title--exam {
	color: var(--color-primary-element);
	font-weight: 700;
}

.station-date {
	font-size: 0.7em;
	color: var(--color-text-maxcontrast);
}

/* Progress bar */
.station-progress {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-top: 4px;
	width: 80px;
}

.progress-bar {
	flex: 1;
	height: 4px;
	border-radius: 2px;
	background: var(--color-border);
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	border-radius: 2px;
	transition: width 0.3s ease;
}

.station--green .progress-fill {
	background: var(--color-success);
}

.station--yellow .progress-fill {
	background: var(--color-warning);
}

.station--red .progress-fill {
	background: var(--color-error);
}

.station--unknown .progress-fill {
	background: var(--color-border);
}

.progress-pct {
	font-size: 0.65em;
	color: var(--color-text-maxcontrast);
	min-width: 28px;
	text-align: right;
}

/* Exam countdown */
.exam-countdown-label {
	font-size: 0.75em;
	font-weight: 600;
	color: var(--color-primary-element);
	margin-top: 2px;
}

.exam-countdown--today {
	color: var(--color-error);
}

/* Legend */
.timeline-legend {
	display: flex;
	gap: 16px;
	padding: 4px 0 0;
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
}

.legend-item {
	display: flex;
	align-items: center;
	gap: 4px;
}

.legend-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
}

.legend-dot--green { background: var(--color-success); }
.legend-dot--yellow { background: var(--color-warning); }
.legend-dot--red { background: var(--color-error); }

/* Responsive: vertical on mobile */
@media (max-width: 768px) {
	.timeline-track {
		flex-direction: column;
		align-items: flex-start;
		overflow-x: visible;
		padding: 8px 0 8px 24px;
	}

	.timeline-line {
		top: 0;
		bottom: 0;
		left: 24px;
		right: auto;
		width: 3px;
		height: auto;
	}

	.timeline-station {
		flex-direction: row;
		align-items: center;
		min-width: unset;
		padding: 8px 0;
	}

	.station-dot {
		margin-bottom: 0;
		margin-right: 12px;
		flex-shrink: 0;
	}

	.station-body {
		align-items: flex-start;
		text-align: left;
	}

	.station-title {
		max-width: none;
		white-space: normal;
	}

	.station-progress {
		width: 100px;
	}

	.timeline-legend {
		flex-wrap: wrap;
		padding-left: 24px;
	}
}
</style>
