<template>
	<div class="instructor-dashboard">
		<div class="dashboard-header">
			<h3>{{ t('learning', 'Instructor Dashboard') }}</h3>
			<NcButton type="tertiary" @click="loadDashboard">
				{{ t('learning', 'Refresh') }}
			</NcButton>
		</div>

		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="44" />
			<p>{{ t('learning', 'Loading...') }}</p>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-if="!loading">
			<!-- Summary cards -->
			<p class="section-label">{{ t('learning', 'Overview') }}</p>
			<div class="summary-cards">
				<div class="summary-card card-courses">
					<span class="summary-value">{{ totalCourses }}</span>
					<span class="summary-label">{{ t('learning', 'Courses') }}</span>
				</div>
				<div class="summary-card card-students">
					<span class="summary-value">{{ totalStudents }}</span>
					<span class="summary-label">{{ t('learning', 'Students') }}</span>
				</div>
				<div class="summary-card card-active">
					<span class="summary-value">{{ activeCourses }}</span>
					<span class="summary-label">{{ t('learning', 'Active') }}</span>
				</div>
				<div class="summary-card card-pools">
					<span class="summary-value">{{ totalPools }}</span>
					<span class="summary-label">{{ t('learning', 'Pools') }}</span>
				</div>
			</div>

			<!-- Course cards -->
			<div v-if="courses.length > 0">
				<p class="section-label">{{ t('learning', 'Your Courses') }}</p>
				<div class="course-cards">
					<div v-for="course in courses"
						:key="course.id"
						class="dashboard-course-card"
						tabindex="0" role="button"
						@click="$emit('selectCourse', course)"
						@keydown.enter="$emit('selectCourse', course)"
						@keydown.space.prevent="$emit('selectCourse', course)">
						<div class="card-header">
							<h4>{{ course.title }}</h4>
							<span class="status-badge" :class="course.status">
								{{ course.status === 'active' ? t('learning', 'Active') : t('learning', 'Archived') }}
							</span>
						</div>
						<p v-if="course.description" class="card-description">
							{{ truncate(course.description, 100) }}
						</p>
						<div class="card-stats">
							<div class="stat">
								<span class="stat-value">{{ course.pool_count || 0 }}</span>
								<span class="stat-label">{{ t('learning', 'Pools') }}</span>
							</div>
							<div class="stat">
								<span class="stat-value">{{ course.student_count || 0 }}</span>
								<span class="stat-label">{{ t('learning', 'Students') }}</span>
							</div>
						</div>
						<div class="card-footer">
							{{ t('learning', 'Created {date}', { date: formatDate(course.created_at) }) }}
						</div>
					</div>
				</div>
			</div>

			<NcEmptyContent v-if="courses.length === 0 && !loading"
				:name="t('learning', 'No courses yet')">
				<template #description>
					{{ t('learning', 'Create your first course to get started') }}
				</template>
			</NcEmptyContent>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'InstructorDashboard',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: false,
			error: '',
			courses: [],
			uniqueStudentCount: 0,
		}
	},

	computed: {
		totalCourses() {
			return this.courses.length
		},
		activeCourses() {
			return this.courses.filter(c => c.status === 'active').length
		},
		totalStudents() {
			return this.uniqueStudentCount
		},
		totalPools() {
			return this.courses.reduce((sum, c) => sum + (c.pool_count || 0), 0)
		},
	},

	mounted() {
		this.loadDashboard()
	},

	methods: {
		async loadDashboard() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/learning/api/instructor/dashboard')
				const response = await axios.get(url)
				this.courses = response.data.courses || []
				this.uniqueStudentCount = response.data.unique_student_count || 0
			} catch (err) {
				console.error('Failed to load dashboard:', err)
				this.error = t('learning', 'Failed to load dashboard.')
			} finally {
				this.loading = false
			}
		},

		formatDate(timestamp) {
			if (!timestamp) return ''
			try {
				const date = new Date(timestamp * 1000)
				return date.toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				})
			} catch {
				return ''
			}
		},

		truncate(text, maxLength) {
			if (!text) return ''
			return text.length > maxLength ? text.substring(0, maxLength) + '...' : text
		},
	},
}
</script>

<style scoped>
.instructor-dashboard {
	max-width: 1200px;
	margin: 0 auto;
}

.dashboard-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 24px;
}

.dashboard-header h3 {
	margin: 0;
	font-size: 1.3em;
	font-weight: 700;
	color: var(--color-main-text);
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
}

.loading-container p { margin-top: 12px; }

/* Section Labels — Data Observatory Style */
.section-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
	margin: 0 0 12px;
}

/* Summary cards */
.summary-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
	margin-bottom: 32px;
}

.summary-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-left: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px 20px 20px 18px;
	text-align: center;
	display: flex;
	flex-direction: column;
	gap: 4px;
	position: relative;
	overflow: hidden;
	transition: transform 0.2s, box-shadow 0.2s;
}

.summary-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px color-mix(in srgb, var(--color-main-text) 8%, transparent);
}

/* Emoji watermarks */
.summary-card::after {
	position: absolute;
	right: 10px;
	bottom: 6px;
	font-size: 32px;
	opacity: 0.08;
	pointer-events: none;
	line-height: 1;
}

.card-courses { border-left-color: var(--color-primary-element); }
.card-courses::after { content: '\1F4DA'; }

.card-students { border-left-color: var(--color-success); }
.card-students::after { content: '\1F393'; }

.card-active { border-left-color: var(--color-warning); }
.card-active::after { content: '\26A1'; }

.card-pools { border-left-color: var(--color-primary-element); }
.card-pools::after { content: '\1F4CB'; }

.summary-value {
	font-size: 2em;
	font-weight: 700;
	color: var(--color-primary-element);
	line-height: 1.2;
}

.card-students .summary-value { color: var(--color-success); }
.card-active .summary-value { color: var(--color-warning); }

.summary-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

/* Course cards */
.course-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 16px;
}

.dashboard-course-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px;
	cursor: pointer;
	transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.dashboard-course-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px color-mix(in srgb, var(--color-main-text) 8%, transparent);
	border-color: var(--color-primary-element);
}

.card-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 8px;
}

.card-header h4 {
	margin: 0;
	font-size: 1.1em;
	font-weight: 600;
	color: var(--color-main-text);
	word-break: break-word;
	flex: 1;
}

.status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	white-space: nowrap;
	flex-shrink: 0;
}

.status-badge.active {
	background: color-mix(in srgb, var(--color-success) 15%, transparent);
	color: var(--color-success);
}

.status-badge.archived {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.card-description {
	margin: 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.card-stats {
	display: flex;
	gap: 24px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
}

.stat { display: flex; flex-direction: column; gap: 2px; }

.stat-value {
	font-size: 1.3em;
	font-weight: 700;
	color: var(--color-main-text);
}

.stat-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.card-footer {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	margin-top: auto;
}

@media (max-width: 768px) {
	.summary-cards { grid-template-columns: repeat(2, 1fr); }
	.course-cards { grid-template-columns: 1fr; }
}

@media (max-width: 480px) {
	.summary-card { padding: 14px; }
	.summary-value { font-size: 1.6em; }
	.summary-card::after { font-size: 24px; }
}
</style>
