<template>
	<div class="student-detail">
		<NcButton type="tertiary" @click="$emit('back')">
			{{ t('learning', '\u2190 Back to Course') }}
		</NcButton>

		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="44" />
			<p>{{ t('learning', 'Loading student details...') }}</p>
		</div>

		<NcNoteCard v-if="error" type="error" class="detail-error">
			{{ error }}
		</NcNoteCard>

		<template v-if="!loading && data">
			<!-- Header card -->
			<div class="student-header-card">
				<div class="student-header-top">
					<div class="student-identity">
						<span class="student-avatar">{{ (data.display_name || data.user_id).charAt(0).toUpperCase() }}</span>
						<h3 class="student-name">{{ data.display_name || data.user_id }}</h3>
					</div>
					<div class="student-header-badges">
						<span class="level-pill" :class="levelClass(data.xp.level)">
							Lv.{{ data.xp.level }}
						</span>
						<span v-if="data.streak.current_streak > 0" class="streak-badge">
							<span class="streak-flames"><span v-for="i in Math.min(data.streak.current_streak, 5)" :key="i" class="streak-flame" :style="{ animationDelay: (i * 0.1) + 's' }">&#x1F525;</span></span> {{ data.streak.current_streak }}d
						</span>
					</div>
				</div>
				<div class="xp-bar-container">
					<div class="xp-bar-fill" :style="{ width: data.xp.level_progress_pct + '%' }" />
				</div>
				<div class="xp-bar-labels">
					<span>{{ Number(data.xp.total_xp).toLocaleString() }} / {{ Number(data.xp.total_xp + data.xp.xp_to_next_level - data.xp.xp_in_level).toLocaleString() }} XP</span>
					<span>{{ data.stats.total_sessions }} {{ t('learning', 'Sessions') }} &middot; {{ data.stats.total_mastered }} {{ t('learning', 'Mastered') }}</span>
				</div>
				<div class="student-meta">
					{{ t('learning', 'Enrolled {date}', { date: formatDate(data.stats.enrolled_at) }) }}
					<span v-if="data.streak.last_activity_date"> &middot; {{ t('learning', 'Last active: {date}', { date: formatLastActive(data.streak.last_activity_date) }) }}</span>
				</div>
			</div>

			<!-- Badges section -->
			<div class="student-section">
				<p class="section-label">{{ t('learning', 'Badges ({earned}/{total})', { earned: earnedBadgeCount, total: data.badges.length }) }}</p>
				<div class="badge-grid">
					<span v-for="badge in data.badges"
						:key="badge.badge_id"
						class="badge-item"
						:class="{ 'badge-earned': badge.earned, 'badge-locked': !badge.earned }"
						:title="badge.name + ': ' + badge.description">
						{{ badge.emoji }}
					</span>
				</div>
				<div v-if="data.badge_progress.length > 0" class="badge-progress-section">
					<div v-for="bp in data.badge_progress" :key="bp.badge_id" class="badge-progress-row">
						<span class="badge-progress-name">{{ bp.emoji }} {{ bp.name }}</span>
						<div class="badge-progress-bar-container">
							<div class="badge-progress-bar-fill" :style="{ width: bp.percentage + '%' }" />
						</div>
						<span class="badge-progress-pct">{{ bp.percentage }}%</span>
					</div>
				</div>
			</div>

			<!-- Leitner boxes per pool -->
			<div class="student-section">
				<p class="section-label">{{ t('learning', 'Leitner Boxes per Pool') }}</p>
				<div v-if="data.pools.length > 0" class="pool-boxes-list">
					<div v-for="pool in data.pools" :key="pool.pool_id" class="pool-box-card">
						<div class="pool-box-header">
							<span class="pool-box-name">{{ pool.pool_name }}</span>
							<span class="pool-box-mastery" :class="masteryClass(pool.mastery_pct)">
								{{ pool.mastery_pct }}% {{ t('learning', 'mastered') }}
							</span>
						</div>
						<div class="leitner-boxes">
							<div v-for="n in 5" :key="n" class="leitner-box" :class="'box-' + n">
								<span class="box-label">{{ n }}</span>
								<span class="box-count">{{ pool.boxes['box_' + n] || 0 }}</span>
							</div>
						</div>
						<div class="pool-box-footer">
							{{ pool.total }} {{ t('learning', 'cards') }} &middot;
							{{ pool.accuracy }}% {{ t('learning', 'accuracy') }}
						</div>
					</div>
				</div>
				<p v-else class="empty-hint">{{ t('learning', 'No Leitner data for this student in course pools.') }}</p>
			</div>

			<!-- Recent sessions -->
			<div class="student-section">
				<p class="section-label">{{ t('learning', 'Recent Sessions') }}</p>
				<div v-if="data.recent_sessions.length > 0" class="session-list">
					<div v-for="(sess, idx) in data.recent_sessions" :key="idx" class="session-item">
						<span class="session-date">{{ formatDate(sess.completed_at) }}</span>
						<span class="session-pool">{{ sess.pool_name }}</span>
						<span class="session-mode">{{ sess.mode }}</span>
						<span class="session-score">{{ sess.score }}</span>
					</div>
				</div>
				<p v-else class="empty-hint">{{ t('learning', 'No sessions found for this student in course pools.') }}</p>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { formatRelativeDateString } from '../format.js'

export default {
	name: 'StudentDetail',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		studentId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			data: null,
		}
	},

	computed: {
		earnedBadgeCount() {
			if (!this.data || !this.data.badges) return 0
			return this.data.badges.filter(b => b.earned).length
		},
	},

	watch: {
		studentId: {
			immediate: true,
			handler() {
				this.fetchStudentDetail()
			},
		},
		courseId() {
			this.fetchStudentDetail()
		},
	},

	methods: {
		async fetchStudentDetail() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/students/{studentId}', {
					courseId: this.courseId,
					studentId: this.studentId,
				})
				const response = await axios.get(url)
				this.data = response.data
			} catch (err) {
				console.error('Failed to fetch student detail:', err)
				if (err.response?.status === 403) {
					this.error = t('learning', 'You do not have permission to view this student.')
				} else if (err.response?.status === 404) {
					this.error = t('learning', 'Student not found in this course.')
				} else {
					this.error = t('learning', 'Failed to load student details.')
				}
			} finally {
				this.loading = false
			}
		},

		levelClass(level) {
			const l = level || 1
			if (l >= 10) return 'level-gold'
			if (l >= 5) return 'level-blue'
			return 'level-grey'
		},

		masteryClass(pct) {
			if (pct >= 80) return 'mastery-high-text'
			if (pct >= 40) return 'mastery-medium-text'
			return 'mastery-low-text'
		},

		formatDate(timestamp) {
			if (!timestamp) return ''
			try {
				const ts = typeof timestamp === 'number' ? timestamp * 1000 : Date.parse(timestamp)
				const date = new Date(ts)
				if (isNaN(date.getTime())) return String(timestamp)
				return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
			} catch {
				return String(timestamp)
			}
		},

		formatLastActive(dateStr) {
			return formatRelativeDateString(dateStr)
		},
	},
}
</script>

<style scoped>
.student-detail {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
}

.loading-container p { margin-top: 12px; }

.detail-error { margin-bottom: 16px; }

/* Section Label — Data Observatory Style */
.section-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
	margin: 0 0 12px;
}

/* Header card — Dot Grid Background */
.student-header-card {
	background:
		radial-gradient(circle, color-mix(in srgb, var(--color-primary-element) 12%, transparent) 1px, transparent 1px),
		color-mix(in srgb, var(--color-primary-element) 4%, transparent);
	background-size: 16px 16px, auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 24px;
	margin: 16px 0 28px;
}

.student-header-top {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.student-identity {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.student-avatar {
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: color-mix(in srgb, var(--color-primary-element) 18%, transparent);
	color: var(--color-primary-element);
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 700;
	font-size: 18px;
	flex-shrink: 0;
}

.student-name {
	margin: 0;
	font-size: 1.3em;
	font-weight: 700;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.student-header-badges {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-shrink: 0;
}

.level-pill {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 700;
	white-space: nowrap;
}

.level-grey { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.level-blue { background: color-mix(in srgb, var(--color-primary-element) 15%, transparent); color: var(--color-primary-element); }
.level-gold { background: color-mix(in srgb, var(--color-warning) 20%, transparent); color: var(--color-warning); }

.streak-badge {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-warning);
	display: inline-flex;
	align-items: center;
	gap: 2px;
}

.streak-flames { display: inline-flex; gap: 0; }
.streak-flame { display: inline-block; animation: flame-dance 0.8s ease-in-out infinite alternate; font-size: 0.9em; }
@keyframes flame-dance { 0% { transform: translateY(0) scale(1); } 100% { transform: translateY(-1px) scale(1.1); } }
@media (prefers-reduced-motion: reduce) { .streak-flame { animation: none; } }

/* XP Bar — Gradient + Shimmer (consistent with AnalyticsDashboard) */
.xp-bar-container {
	height: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	overflow: hidden;
	margin-bottom: 8px;
}

.xp-bar-fill {
	height: 100%;
	background: linear-gradient(90deg, var(--color-primary-element), var(--color-success));
	border-radius: 6px;
	transition: width 0.8s ease-in-out;
	position: relative;
	overflow: hidden;
}

.xp-bar-fill::after {
	content: '';
	position: absolute;
	inset: 0;
	background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.3) 50%, transparent 100%);
	animation: shimmer 2.5s ease-in-out infinite;
}

.xp-bar-labels {
	display: flex;
	justify-content: space-between;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.student-meta {
	margin-top: 8px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

/* Sections */
.student-section { margin-bottom: 28px; }

/* Badge grid */
.badge-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 12px;
}

.badge-item {
	font-size: 1.5em;
	width: 44px;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius-large, 8px);
	border: 1px solid var(--color-border);
	transition: transform 0.2s;
}

.badge-earned {
	background: color-mix(in srgb, var(--color-warning) 10%, transparent);
}

.badge-earned:hover { transform: scale(1.12); }

.badge-locked {
	opacity: 0.28;
	filter: grayscale(100%);
}

.badge-progress-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.badge-progress-row {
	display: flex;
	align-items: center;
	gap: 10px;
}

.badge-progress-name {
	width: 140px;
	flex-shrink: 0;
	font-size: 0.85em;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.badge-progress-bar-container {
	flex: 1;
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 20px;
	overflow: hidden;
}

.badge-progress-bar-fill {
	height: 100%;
	background: linear-gradient(90deg, var(--color-primary-element), var(--color-success));
	border-radius: 20px;
	transition: width 0.5s ease;
}

.badge-progress-pct {
	width: 40px;
	text-align: right;
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

/* Pool boxes */
.pool-boxes-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pool-box-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 16px;
	transition: transform 0.2s, box-shadow 0.2s;
}

.pool-box-card:hover {
	transform: translateY(-1px);
	box-shadow: 0 3px 10px color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.pool-box-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 10px;
}

.pool-box-name { font-weight: 600; color: var(--color-main-text); }

.pool-box-mastery {
	font-size: 0.85em;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 10px;
}

.mastery-high-text { color: var(--color-success); background: color-mix(in srgb, var(--color-success) 10%, transparent); }
.mastery-medium-text { color: var(--color-warning); background: color-mix(in srgb, var(--color-warning) 10%, transparent); }
.mastery-low-text { color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, transparent); }

/* Leitner Boxes — Color Gradient Red → Green */
.leitner-boxes {
	display: flex;
	gap: 8px;
	margin-bottom: 8px;
}

.leitner-box {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 10px 4px;
	border-radius: var(--border-radius-large, 8px);
	border-left: 3px solid transparent;
}

.leitner-box.box-1 { background: color-mix(in srgb, var(--color-error) 8%, transparent); border-left-color: var(--color-error); }
.leitner-box.box-2 { background: color-mix(in srgb, var(--color-warning) 8%, transparent); border-left-color: var(--color-warning); }
.leitner-box.box-3 { background: var(--color-background-dark); border-left-color: var(--color-primary-element); }
.leitner-box.box-4 { background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-left-color: var(--color-primary-element); }
.leitner-box.box-5 { background: color-mix(in srgb, var(--color-success) 10%, transparent); border-left-color: var(--color-success); }

.box-label {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	letter-spacing: 0.5px;
	margin-bottom: 2px;
}

.box-count {
	font-size: 1.2em;
	font-weight: 700;
	color: var(--color-main-text);
}

.pool-box-footer {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

/* Session list */
.session-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.session-item {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	font-size: 0.9em;
	transition: transform 0.2s, box-shadow 0.2s;
}

.session-item:hover {
	transform: translateY(-1px);
	box-shadow: 0 2px 8px color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.session-date {
	width: 100px;
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.session-pool {
	flex: 1;
	font-weight: 500;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.session-mode {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 10px;
	border-radius: 10px;
	background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
	color: var(--color-primary-element);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.session-score {
	font-weight: 700;
	color: var(--color-main-text);
	white-space: nowrap;
}

.empty-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-style: italic;
	padding: 16px;
	text-align: center;
	background: color-mix(in srgb, var(--color-background-dark) 50%, transparent);
	border-radius: var(--border-radius-large, 8px);
}

/* Shimmer Animation */
@keyframes shimmer {
	0% { transform: translateX(-100%); }
	100% { transform: translateX(100%); }
}

@media (prefers-reduced-motion: reduce) {
	.xp-bar-fill::after { animation: none; }
	.badge-earned:hover { transform: none; }
	.pool-box-card:hover,
	.session-item:hover { transform: none; }
}

@media (max-width: 768px) {
	.student-detail { padding: 12px; }
	.student-header-top { flex-direction: column; align-items: flex-start; gap: 8px; }
	.student-header-card { padding: 16px; }
	.leitner-boxes { flex-wrap: wrap; }
	.session-item { flex-wrap: wrap; gap: 8px; }
	.session-date { width: auto; }
	.badge-progress-name { width: 100px; }
}

@media (max-width: 480px) {
	.student-avatar { width: 36px; height: 36px; font-size: 15px; }
	.student-name { font-size: 1.1em; }
}
</style>
