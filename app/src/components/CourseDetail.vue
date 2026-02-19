<template>
	<div class="course-detail">
		<!-- Header -->
		<div class="course-detail-header">
			<NcButton type="tertiary" @click="$emit('back')">
				{{ t('learning', '\u2190 Back to Courses') }}
			</NcButton>
			<div class="header-title">
				<h3>{{ course ? course.title : t('learning', 'Loading...') }}</h3>
				<span v-if="course && course.status" class="status-badge" :class="course.status">
					{{ course.status === 'active' ? t('learning', 'Active') : t('learning', 'Archived') }}
				</span>
			</div>
			<p v-if="course && course.description" class="course-description">
				{{ course.description }}
			</p>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="loading-container">
			<div class="icon-loading" />
			<p>{{ t('learning', 'Loading course details...') }}</p>
		</div>

		<!-- Error state -->
		<NcNoteCard v-if="error" type="error" class="detail-error">
			{{ error }}
		</NcNoteCard>

		<template v-if="!loading && course">
			<!-- Tab selector (instructor) -->
			<div v-if="isInstructor" class="tab-selector">
				<button v-for="tab in instructorTabs"
					:key="tab.id"
					class="tab-button"
					:class="{ active: currentTab === tab.id }"
					@click="currentTab = tab.id">
					{{ tab.label }}
				</button>
			</div>

			<!-- Pools Tab -->
			<div v-if="currentTab === 'pools'" class="pools-section">
				<div class="section-header">
					<h4>{{ t('learning', 'Course Pools') }}</h4>
					<NcButton v-if="isInstructor" @click="openAddPoolModal">
						{{ t('learning', '+ Add Pool') }}
					</NcButton>
				</div>

				<div v-if="coursePools.length > 0" class="pool-list">
					<div v-for="pool in sortedPools"
						:key="pool.id"
						class="pool-item"
						tabindex="0" role="button"
						@click="$emit('openPool', pool.pool_id)"
						@keydown.enter="$emit('openPool', pool.pool_id)"
						@keydown.space.prevent="$emit('openPool', pool.pool_id)">
						<span v-if="isInstructor" class="pool-sort-order">{{ (pool.sort_order || 0) + 1 }}.</span>
						<div class="pool-info">
							<span class="pool-name">{{ pool.pool_name }}</span>
							<span class="pool-questions">
								{{ t('learning', '{n} questions', { n: pool.question_count || 0 }) }}
							</span>
						</div>
						<div class="pool-badges">
							<span v-if="pool.required" class="required-badge">
								{{ t('learning', 'Required') }}
							</span>
						</div>
						<NcButton v-if="isInstructor"
							type="tertiary-no-background"
							class="remove-pool-btn"
							:aria-label="t('learning', 'Remove pool')"
							@click.stop="confirmRemovePool(pool)">
							&times;
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-if="coursePools.length === 0"
					:name="t('learning', 'No pools assigned')">
					<template #description>
						<span v-if="isInstructor">{{ t('learning', 'Add question pools to this course') }}</span>
						<span v-else>{{ t('learning', 'No question pools are available in this course yet.') }}</span>
					</template>
				</NcEmptyContent>

				<!-- Student progress per pool (student view) -->
				<div v-if="!isInstructor && studentProgress.length > 0" class="student-own-progress">
					<h4>{{ t('learning', 'My Progress') }}</h4>
					<div class="progress-bars">
						<div v-for="prog in studentProgress"
							:key="prog.pool_id"
							class="progress-row">
							<span class="progress-pool-name">{{ prog.pool_name }}</span>
							<div class="progress-bar-container">
								<div class="progress-bar-fill"
									:class="masteryClass(prog.mastery)"
									:style="{ width: prog.mastery + '%' }" />
							</div>
							<span class="progress-percent">{{ prog.mastery }}%</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Members Tab (instructor only) -->
			<div v-if="currentTab === 'members' && isInstructor" class="members-section">
				<div class="section-header">
					<h4>{{ t('learning', 'Members ({n})', { n: courseMembers.length }) }}</h4>
				</div>

				<!-- Add member form -->
				<div class="add-member-form">
					<NcTextField :value.sync="newMemberUsername"
						:label="t('learning', 'Username')"
						:placeholder="t('learning', 'Enter username to add...')"
						:disabled="addingMember"
						@keydown.enter="addMember" />
					<NcButton type="secondary"
						:disabled="!newMemberUsername.trim() || addingMember"
						@click="addMember">
						{{ addingMember ? t('learning', 'Adding...') : t('learning', 'Add') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="memberError" type="error" class="member-error">
					{{ memberError }}
				</NcNoteCard>

				<div v-if="courseMembers.length > 0" class="member-list">
					<div v-for="member in courseMembers"
						:key="member.id"
						class="member-item">
						<div class="member-info">
							<span class="member-name">{{ member.user_id }}</span>
							<span class="member-role-badge" :class="member.role">
								{{ member.role }}
							</span>
							<span class="member-date">
								{{ t('learning', 'Enrolled {date}', { date: formatDate(member.enrolled_at) }) }}
							</span>
						</div>
						<div class="member-actions">
							<NcButton type="tertiary"
								:disabled="savingMember === member.id"
								@click="toggleMemberRole(member)">
								{{ member.role === 'student'
									? t('learning', 'Make Instructor')
									: t('learning', 'Make Student') }}
							</NcButton>
							<NcButton type="tertiary-no-background"
								:aria-label="t('learning', 'Remove member')"
								:disabled="savingMember === member.id"
								@click="confirmRemoveMember(member)">
								&times;
							</NcButton>
						</div>
					</div>
				</div>

				<NcEmptyContent v-if="courseMembers.length === 0"
					:name="t('learning', 'No members yet')">
					<template #description>
						{{ t('learning', 'Add members by entering their username above.') }}
					</template>
				</NcEmptyContent>
			</div>

			<!-- Progress Tab (instructor only) -->
			<div v-if="currentTab === 'progress' && isInstructor" class="progress-section">
				<div class="section-header">
					<h4>{{ t('learning', 'Student Progress') }}</h4>
					<NcButton type="tertiary" @click="fetchProgress">
						{{ t('learning', 'Refresh') }}
					</NcButton>
				</div>

				<div v-if="progressLoading" class="loading-container">
					<div class="icon-loading" />
					<p>{{ t('learning', 'Loading progress data...') }}</p>
				</div>

				<div v-else-if="progressData.length > 0" class="progress-table-container" role="region" :aria-label="t('learning', 'Student progress')">
					<table class="progress-table">
						<thead>
							<tr>
								<th class="student-col" scope="col">{{ t('learning', 'Student') }}</th>
								<th v-for="pool in coursePools"
									:key="'th-' + pool.id"
									class="pool-col"
									scope="col">
									{{ pool.pool_name }}
								</th>
								<th class="overall-col" scope="col">{{ t('learning', 'Overall') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in progressData" :key="row.user_id">
								<td class="student-col">{{ row.user_id }}</td>
								<td v-for="pool in coursePools"
									:key="'td-' + pool.id"
									class="pool-col"
									:class="masteryClass(getPoolMastery(row, pool.pool_id))">
									{{ getPoolMastery(row, pool.pool_id) !== null
										? getPoolMastery(row, pool.pool_id) + '%'
										: '-' }}
								</td>
								<td class="overall-col"
									:class="masteryClass(row.overall_mastery)">
									{{ row.overall_mastery !== null && row.overall_mastery !== undefined
										? row.overall_mastery + '%'
										: '-' }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<NcEmptyContent v-else
					:name="t('learning', 'No progress data')">
					<template #description>
						{{ t('learning', 'Progress will appear once students start working on course pools.') }}
					</template>
				</NcEmptyContent>
			</div>
		</template>

		<!-- Add Pool Modal -->
		<NcModal v-if="showAddPoolModal" @close="showAddPoolModal = false">
			<div class="modal-content">
				<h3>{{ t('learning', 'Add Pool to Course') }}</h3>

				<div v-if="poolsLoading" class="loading-container">
					<div class="icon-loading" />
					<p>{{ t('learning', 'Loading available pools...') }}</p>
				</div>

				<template v-else>
					<NcNoteCard v-if="poolModalError" type="error" class="modal-error">
						{{ poolModalError }}
					</NcNoteCard>

					<div v-if="availablePools.length > 0" class="pool-select-list">
						<div v-for="pool in availablePools"
							:key="pool.id"
							class="pool-select-item"
							:class="{ disabled: isPoolAlreadyAdded(pool.id) }"
							tabindex="0" role="button"
							@click="addPool(pool)"
							@keydown.enter="addPool(pool)"
							@keydown.space.prevent="addPool(pool)">
							<div class="pool-select-info">
								<span class="pool-select-name">{{ pool.name }}</span>
								<span v-if="pool.description" class="pool-select-desc">{{ pool.description }}</span>
							</div>
							<span v-if="isPoolAlreadyAdded(pool.id)" class="pool-already-added">
								{{ t('learning', 'Already added') }}
							</span>
						</div>
					</div>

					<NcEmptyContent v-else
						:name="t('learning', 'No pools available')">
						<template #description>
							{{ t('learning', 'Create question pools first before adding them to a course.') }}
						</template>
					</NcEmptyContent>
				</template>
			</div>
		</NcModal>

		<!-- Remove pool confirmation modal -->
		<NcModal v-if="showRemovePoolModal" @close="showRemovePoolModal = false" size="small">
			<div class="modal-content">
				<h3>{{ t('learning', 'Remove Pool') }}</h3>
				<p>{{ t('learning', 'Remove "{name}" from this course? Students will lose access to these questions.', { name: removingPool ? removingPool.pool_name : '' }) }}</p>
				<div class="modal-actions">
					<NcButton type="tertiary"
						:disabled="savingPool"
						@click="showRemovePoolModal = false">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="error"
						:disabled="savingPool"
						@click="removePool">
						{{ savingPool ? t('learning', 'Removing...') : t('learning', 'Remove') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Remove member confirmation modal -->
		<NcModal v-if="showRemoveMemberModal" @close="showRemoveMemberModal = false" size="small">
			<div class="modal-content">
				<h3>{{ t('learning', 'Remove Member') }}</h3>
				<p>{{ t('learning', 'Remove "{name}" from this course?', { name: removingMember ? removingMember.user_id : '' }) }}</p>
				<div class="modal-actions">
					<NcButton type="tertiary"
						:disabled="savingMember !== null"
						@click="showRemoveMemberModal = false">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="error"
						:disabled="savingMember !== null"
						@click="removeMember">
						{{ savingMember !== null ? t('learning', 'Removing...') : t('learning', 'Remove') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

export default {
	name: 'CourseDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcModal,
		NcTextField,
		NcActions,
		NcActionButton,
		NcNoteCard,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		userRole: {
			type: String,
			required: true,
			validator(value) {
				return ['instructor', 'student'].includes(value)
			},
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			course: null,
			coursePools: [],
			courseMembers: [],
			currentTab: 'pools',

			// Pool modal
			showAddPoolModal: false,
			poolsLoading: false,
			poolModalError: '',
			allPools: [],
			savingPool: false,
			showRemovePoolModal: false,
			removingPool: null,

			// Members
			newMemberUsername: '',
			addingMember: false,
			memberError: '',
			savingMember: null,
			showRemoveMemberModal: false,
			removingMember: null,

			// Progress
			progressLoading: false,
			progressData: [],

			// Student's own progress
			studentProgress: [],
		}
	},

	computed: {
		isInstructor() {
			return this.course && this.course.is_instructor
		},
		instructorTabs() {
			return [
				{ id: 'pools', label: this.t('learning', 'Pools') },
				{ id: 'members', label: this.t('learning', 'Members') },
				{ id: 'progress', label: this.t('learning', 'Progress') },
			]
		},
		sortedPools() {
			return [...this.coursePools].sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
		},
		availablePools() {
			return this.allPools
		},
	},

	watch: {
		courseId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchCourseDetail()
				}
			},
		},
		currentTab(tab) {
			if (tab === 'progress' && this.isInstructor && this.progressData.length === 0) {
				this.fetchProgress()
			}
		},
	},

	methods: {
		async fetchCourseDetail() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}', { id: this.courseId })
				const response = await axios.get(url)
				this.course = response.data
				this.coursePools = response.data.pools || []
				this.courseMembers = response.data.members || []

				// Default tab for students
				if (!this.course.is_instructor) {
					this.currentTab = 'pools'
					this.fetchStudentProgress()
				}
			} catch (err) {
				console.error('Failed to fetch course detail:', err)
				if (err.response?.status === 404) {
					this.error = this.t('learning', 'Course not found.')
				} else if (err.response?.status === 403) {
					this.error = this.t('learning', 'You do not have access to this course.')
				} else {
					this.error = this.t('learning', 'Failed to load course details. Please try again.')
				}
			} finally {
				this.loading = false
			}
		},

		async fetchStudentProgress() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}/progress', { id: this.courseId })
				const response = await axios.get(url)
				// For students, the API may return just their own progress
				const data = response.data
				if (Array.isArray(data)) {
					this.studentProgress = data
				} else if (data && data.pools) {
					this.studentProgress = data.pools
				} else if (data && Array.isArray(data.students) && data.students.length > 0) {
					this.studentProgress = data.students[0].pools || []
				}
			} catch (err) {
				// Student progress is optional, do not block the view
				console.error('Failed to fetch student progress:', err)
			}
		},

		async fetchProgress() {
			this.progressLoading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}/progress', { id: this.courseId })
				const response = await axios.get(url)
				// FIX-HI-3: Backend returns {students: [...]} not a plain array
				const data = response.data
				if (Array.isArray(data)) {
					this.progressData = data
				} else if (data && Array.isArray(data.students)) {
					// Compute overall_mastery for each student
					this.progressData = data.students.map(student => {
						let totalMastered = 0
						let totalQuestions = 0
						if (Array.isArray(student.pools)) {
							for (const p of student.pools) {
								totalMastered += p.mastered || 0
								totalQuestions += p.total_questions || 0
							}
						}
						student.overall_mastery = totalQuestions > 0
							? Math.round(totalMastered / totalQuestions * 100)
							: null
						return student
					})
				} else {
					this.progressData = []
				}
			} catch (err) {
				console.error('Failed to fetch progress:', err)
				this.error = this.t('learning', 'Failed to load progress data.')
			} finally {
				this.progressLoading = false
			}
		},

		async openAddPoolModal() {
			this.showAddPoolModal = true
			this.poolModalError = ''
			if (this.allPools.length === 0) {
				await this.fetchAllPools()
			}
		},

		async fetchAllPools() {
			this.poolsLoading = true
			this.poolModalError = ''
			try {
				const url = generateUrl('/apps/learning/api/pools')
				const response = await axios.get(url)
				// FIX-HI-4: API returns {own:[], shared:[]} not a plain array
				if (Array.isArray(response.data)) {
					this.allPools = response.data
				} else if (response.data && typeof response.data === 'object') {
					this.allPools = [...(response.data.own || []), ...(response.data.shared || [])]
				} else {
					this.allPools = []
				}
			} catch (err) {
				console.error('Failed to fetch pools:', err)
				this.poolModalError = this.t('learning', 'Failed to load available pools.')
			} finally {
				this.poolsLoading = false
			}
		},

		isPoolAlreadyAdded(poolId) {
			return this.coursePools.some(p => p.pool_id === poolId)
		},

		async addPool(pool) {
			if (this.isPoolAlreadyAdded(pool.id)) {
				return
			}

			this.savingPool = true
			this.poolModalError = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}/pools', { id: this.courseId })
				const nextSortOrder = this.coursePools.length > 0
					? Math.max(...this.coursePools.map(p => p.sort_order || 0)) + 1
					: 0
				await axios.post(url, {
					poolId: pool.id,
					sortOrder: nextSortOrder,
					required: true,
				})
				this.showAddPoolModal = false
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to add pool:', err)
				const message = err.response?.data?.error || err.response?.data?.message
				this.poolModalError = message || this.t('learning', 'Failed to add pool to course.')
			} finally {
				this.savingPool = false
			}
		},

		confirmRemovePool(pool) {
			this.removingPool = pool
			this.showRemovePoolModal = true
		},

		async removePool() {
			if (!this.removingPool) {
				return
			}

			this.savingPool = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}/pools/{poolId}', {
					id: this.courseId,
					poolId: this.removingPool.pool_id,
				})
				await axios.delete(url)
				this.showRemovePoolModal = false
				this.removingPool = null
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to remove pool:', err)
				this.error = this.t('learning', 'Failed to remove pool from course.')
			} finally {
				this.savingPool = false
			}
		},

		async addMember() {
			const username = this.newMemberUsername.trim()
			if (!username) {
				return
			}

			this.addingMember = true
			this.memberError = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}/members', { id: this.courseId })
				await axios.post(url, { userId: username })
				this.newMemberUsername = ''
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to add member:', err)
				const message = err.response?.data?.error || err.response?.data?.message
				if (err.response?.status === 404) {
					this.memberError = this.t('learning', 'User "{name}" not found.', { name: username })
				} else if (err.response?.status === 409) {
					this.memberError = this.t('learning', 'User "{name}" is already a member.', { name: username })
				} else {
					this.memberError = message || this.t('learning', 'Failed to add member.')
				}
			} finally {
				this.addingMember = false
			}
		},

		async toggleMemberRole(member) {
			const newRole = member.role === 'student' ? 'instructor' : 'student'
			this.savingMember = member.id
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}/members', { id: this.courseId })
				await axios.post(url, { userId: member.user_id, role: newRole })
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to update member role:', err)
				this.memberError = this.t('learning', 'Failed to update member role.')
			} finally {
				this.savingMember = null
			}
		},

		confirmRemoveMember(member) {
			this.removingMember = member
			this.showRemoveMemberModal = true
		},

		async removeMember() {
			if (!this.removingMember) {
				return
			}

			this.savingMember = this.removingMember.id
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}/members/{memberId}', {
					id: this.courseId,
					memberId: this.removingMember.id,
				})
				await axios.delete(url)
				this.showRemoveMemberModal = false
				this.removingMember = null
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to remove member:', err)
				this.memberError = this.t('learning', 'Failed to remove member.')
			} finally {
				this.savingMember = null
			}
		},

		getPoolMastery(row, poolId) {
			if (!row.pools || !Array.isArray(row.pools)) {
				return null
			}
			// FIX-HI-3: pools is an array, find by pool_id; compute mastery from mastered/total_questions
			const pool = row.pools.find(p => p.pool_id === poolId)
			if (!pool) {
				return null
			}
			if (pool.total_questions > 0) {
				return Math.round((pool.mastered || 0) / pool.total_questions * 100)
			}
			return 0
		},

		masteryClass(mastery) {
			if (mastery === null || mastery === undefined) {
				return ''
			}
			if (mastery >= 80) {
				return 'mastery-high'
			}
			if (mastery >= 40) {
				return 'mastery-medium'
			}
			return 'mastery-low'
		},

		formatDate(timestamp) {
			if (!timestamp) {
				return ''
			}
			try {
				// Backend returns Unix timestamps in seconds
				const ts = typeof timestamp === 'number' ? timestamp * 1000 : Date.parse(timestamp)
				const date = new Date(ts)
				if (isNaN(date.getTime())) {
					return String(timestamp)
				}
				return date.toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				})
			} catch {
				return String(timestamp)
			}
		},
	},
}
</script>

<style scoped>
.course-detail {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

/* Header */
.course-detail-header {
	margin-bottom: 24px;
}

.header-title {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 8px;
}

.header-title h3 {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
	color: var(--color-main-text);
}

.course-description {
	margin: 8px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95em;
	line-height: 1.5;
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
}

.status-badge.active {
	background: color-mix(in srgb, var(--color-success) 15%, transparent);
	color: var(--color-success);
}

.status-badge.archived {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Loading */
.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
}

.loading-container .icon-loading {
	width: 44px;
	height: 44px;
	margin-bottom: 12px;
}

.detail-error {
	margin-bottom: 16px;
}

/* Tab selector */
.tab-selector {
	display: flex;
	gap: 4px;
	margin-bottom: 24px;
	border-bottom: 2px solid var(--color-border);
	padding-bottom: 0;
}

.tab-button {
	padding: 10px 20px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 0.95em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	transition: color 0.15s, border-color 0.15s;
}

.tab-button:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.tab-button.active {
	color: var(--color-primary-element);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
}

/* Section header */
.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.section-header h4 {
	margin: 0;
	font-size: 1.1em;
	font-weight: 600;
	color: var(--color-main-text);
}

/* Pool list */
.pool-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pool-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;
	transition: background 0.15s, box-shadow 0.15s;
}

.pool-item:hover {
	background: var(--color-background-hover);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
}

.pool-sort-order {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-weight: 600;
	min-width: 24px;
	flex-shrink: 0;
}

.pool-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.pool-name {
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pool-questions {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.pool-badges {
	display: flex;
	gap: 6px;
	flex-shrink: 0;
}

.required-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	background: color-mix(in srgb, var(--color-warning) 15%, transparent);
	color: var(--color-warning);
	white-space: nowrap;
}

.remove-pool-btn {
	flex-shrink: 0;
	font-size: 1.3em;
	line-height: 1;
	color: var(--color-text-maxcontrast);
}

.remove-pool-btn:hover {
	color: var(--color-error);
}

/* Student own progress */
.student-own-progress {
	margin-top: 32px;
}

.student-own-progress h4 {
	margin: 0 0 16px 0;
	font-size: 1.1em;
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-bars {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.progress-row {
	display: flex;
	align-items: center;
	gap: 12px;
}

.progress-pool-name {
	width: 140px;
	flex-shrink: 0;
	font-size: 0.9em;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.progress-bar-container {
	flex: 1;
	height: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	overflow: hidden;
}

.progress-bar-fill {
	height: 100%;
	border-radius: 6px;
	transition: width 0.3s ease;
}

.progress-bar-fill.mastery-high {
	background: var(--color-success);
}

.progress-bar-fill.mastery-medium {
	background: var(--color-warning);
}

.progress-bar-fill.mastery-low {
	background: var(--color-error);
}

.progress-percent {
	width: 44px;
	flex-shrink: 0;
	text-align: right;
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-main-text);
}

/* Members section */
.add-member-form {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.add-member-form .nc-text-field {
	flex: 1;
}

.member-error {
	margin-bottom: 12px;
}

.member-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.member-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.member-info {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 1;
	min-width: 0;
}

.member-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.member-role-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: capitalize;
	white-space: nowrap;
}

.member-role-badge.instructor {
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
}

.member-role-badge.student {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.member-date {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.member-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

/* Progress table */
.progress-table-container {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.progress-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.progress-table th,
.progress-table td {
	padding: 10px 14px;
	text-align: center;
	border-bottom: 1px solid var(--color-border);
}

.progress-table th {
	background: var(--color-background-dark);
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
	position: sticky;
	top: 0;
}

.progress-table tbody tr:nth-child(even) {
	background: var(--color-background-hover);
}

.progress-table tbody tr:last-child td {
	border-bottom: none;
}

.student-col {
	text-align: left !important;
	font-weight: 500;
	white-space: nowrap;
}

.pool-col,
.overall-col {
	min-width: 80px;
}

.overall-col {
	font-weight: 600;
}

td.mastery-high {
	background: color-mix(in srgb, var(--color-success) 12%, transparent);
	color: var(--color-success);
	font-weight: 600;
}

td.mastery-medium {
	background: color-mix(in srgb, var(--color-warning) 12%, transparent);
	color: var(--color-warning);
	font-weight: 600;
}

td.mastery-low {
	background: color-mix(in srgb, var(--color-error) 12%, transparent);
	color: var(--color-error);
	font-weight: 600;
}

/* Modal styles */
.modal-content {
	padding: 24px;
	min-width: 320px;
}

.modal-content h3 {
	margin: 0 0 20px 0;
	font-size: 1.2em;
	font-weight: 700;
	color: var(--color-main-text);
}

.modal-error {
	margin-bottom: 16px;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

/* Pool select in modal */
.pool-select-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 400px;
	overflow-y: auto;
}

.pool-select-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;
	transition: background 0.15s;
}

.pool-select-item:hover:not(.disabled) {
	background: var(--color-background-hover);
}

.pool-select-item.disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.pool-select-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.pool-select-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.pool-select-desc {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pool-already-added {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	flex-shrink: 0;
}

/* Responsive */
@media (max-width: 768px) {
	.course-detail {
		padding: 12px;
	}

	.tab-selector {
		overflow-x: auto;
		-webkit-overflow-scrolling: touch;
	}

	.tab-button {
		padding: 8px 14px;
		font-size: 0.9em;
		white-space: nowrap;
	}

	.section-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}

	.member-item {
		flex-direction: column;
		align-items: flex-start;
	}

	.member-actions {
		width: 100%;
		justify-content: flex-end;
	}

	.add-member-form {
		flex-direction: column;
		align-items: stretch;
	}

	.progress-pool-name {
		width: 100px;
	}

	.modal-content {
		padding: 16px;
		min-width: unset;
	}

	.pool-select-list {
		max-height: 300px;
	}
}
</style>
