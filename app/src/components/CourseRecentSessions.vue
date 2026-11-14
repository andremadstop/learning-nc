<template>
	<section class="course-recent-sessions">
		<!-- GitHub #36: without Leitner and gamification "Mein Fortschritt" is gone; its accuracy per
			pool (the rest of the box cards) moves here with the sessions. -->
		<div v-if="showPoolAccuracy && !loading && !error && pools.length > 0" class="course-pool-accuracy">
			<p class="section-label">{{ t('learning', 'Genauigkeit pro Pool') }}</p>
			<PoolAccuracyList :pools="pools" />
		</div>
		<p class="section-label">{{ t('learning', 'Recent Sessions') }}</p>
		<div v-if="loading" class="recent-sessions-loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ t('learning', 'Failed to load student details.') }}
		</NcNoteCard>
		<RecentSessionsList v-else :sessions="sessions" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PoolAccuracyList from './PoolAccuracyList.vue'
import RecentSessionsList from './RecentSessionsList.vue'

/**
 * GitHub #36: the learner's recent sessions in the completion area. Same endpoint as
 * "Mein Fortschritt" (the learner's own student detail), so there is no second source.
 */
export default {
	name: 'CourseRecentSessions',

	components: {
		NcLoadingIcon,
		NcNoteCard,
		PoolAccuracyList,
		RecentSessionsList,
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
		// set by CourseDetail when the learner has no "Mein Fortschritt" (learnerHasProgressTab)
		showPoolAccuracy: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			loading: false,
			error: false,
			sessions: [],
			pools: [],
		}
	},

	watch: {
		courseId: {
			immediate: true,
			handler() {
				this.fetchSessions()
			},
		},
	},

	methods: {
		async fetchSessions() {
			this.loading = true
			this.error = false
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/students/{studentId}', {
					courseId: this.courseId,
					studentId: this.studentId,
				})
				const response = await axios.get(url)
				this.sessions = Array.isArray(response.data?.recent_sessions) ? response.data.recent_sessions : []
				this.pools = Array.isArray(response.data?.pools) ? response.data.pools : []
			} catch (err) {
				this.sessions = []
				this.pools = []
				// An instructor in the student view is no student of the course (404): nothing to list.
				if (err?.response?.status === 404) {
					return
				}
				console.error('Failed to fetch recent sessions:', err)
				this.error = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.course-recent-sessions {
	margin-top: 28px;
}

.course-pool-accuracy {
	margin-bottom: 28px;
}

.section-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
	margin: 0 0 12px;
}

.recent-sessions-loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}
</style>
