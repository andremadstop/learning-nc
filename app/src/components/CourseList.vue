<template>
	<div class="course-list">
		<div class="course-list-header">
			<h3>{{ t('learning', 'Courses') }}</h3>
			<NcButton v-if="userRole === 'instructor'"
				type="primary"
				@click="openCreateModal">
				{{ t('learning', '+ Create Course') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="44" />
			<p>{{ t('learning', 'Loading courses...') }}</p>
		</div>

		<!-- Error state -->
		<NcNoteCard v-if="error" type="error" class="course-list-error">
			{{ error }}
		</NcNoteCard>

		<!-- Student welcome hint -->
		<NcNoteCard v-if="userRole === 'student' && !loading && !hintDismissed('welcome-student')" type="info" class="onboarding-hint">
			{{ t('learning', 'Welcome! Your instructor enrolls you in courses. Open a course, pick a question pool and start learning. Questions you get wrong will come back more often.') }}
			<NcButton type="tertiary" @click="dismissHint('welcome-student')">{{ t('learning', 'Got it') }}</NcButton>
		</NcNoteCard>

		<template v-if="!loading">
			<!-- Instructor: own courses -->
			<div v-if="userRole === 'instructor' && ownCourses.length > 0" class="course-section">
				<h4>{{ t('learning', 'My Courses ({n})', { n: ownCourses.length }) }}</h4>
				<div class="course-grid">
					<div v-for="course in ownCourses"
						:key="'own-' + course.id"
						class="course-card"
						tabindex="0" role="button"
						@click="$emit('selectCourse', course)"
						@keydown.enter="$emit('selectCourse', course)"
						@keydown.space.prevent="$emit('selectCourse', course)">
						<div class="course-card-header">
							<span class="course-title">{{ course.title }}</span>
							<div class="course-card-actions" @click.stop>
								<span class="status-badge" :class="course.status">
									{{ course.status === 'active' ? t('learning', 'Active') : t('learning', 'Archived') }}
								</span>
								<NcActions>
									<NcActionButton @click.stop="editCourse(course)">
										{{ t('learning', 'Edit') }}
									</NcActionButton>
									<NcActionButton @click.stop="toggleStatus(course)">
										{{ course.status === 'active' ? t('learning', 'Archive') : t('learning', 'Activate') }}
									</NcActionButton>
									<NcActionButton @click.stop="confirmDeleteCourse(course)">
										{{ t('learning', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</div>
						</div>
						<p v-if="course.description" class="course-description">
							{{ truncateDescription(course.description) }}
						</p>
						<div class="course-meta">
							<span class="meta-item">
								{{ t('learning', '{n} pools', { n: course.pool_count || 0 }) }}
							</span>
							<span class="meta-item">
								{{ t('learning', '{n} members', { n: course.member_count || 0 }) }}
							</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Enrolled courses (both roles) -->
			<div v-if="enrolledCourses.length > 0" class="course-section">
				<h4>{{ t('learning', 'Enrolled Courses ({n})', { n: enrolledCourses.length }) }}</h4>
				<div class="course-grid">
					<div v-for="course in enrolledCourses"
						:key="'enrolled-' + course.id"
						class="course-card"
						tabindex="0" role="button"
						@click="$emit('selectCourse', course)"
						@keydown.enter="$emit('selectCourse', course)"
						@keydown.space.prevent="$emit('selectCourse', course)">
						<div class="course-card-header">
							<span class="course-title">{{ course.title }}</span>
							<span v-if="course.member_role" class="role-badge">
								{{ course.member_role }}
							</span>
						</div>
						<p v-if="course.description" class="course-description">
							{{ truncateDescription(course.description) }}
						</p>
						<div class="course-meta">
							<span v-if="course.instructor_id" class="meta-item">
								{{ t('learning', 'by {name}', { name: course.instructor_id }) }}
							</span>
							<span class="meta-item">
								{{ t('learning', '{n} pools', { n: course.pool_count || 0 }) }}
							</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Empty state -->
			<NcEmptyContent v-if="ownCourses.length === 0 && enrolledCourses.length === 0"
				:name="emptyStateTitle">
				<template #description>
					{{ emptyStateDescription }}
				</template>
			</NcEmptyContent>
		</template>

		<!-- Create/Edit Modal -->
		<NcModal v-if="showCreateModal" @close="closeModal" @closing="closeModal">
			<div class="modal-content">
				<h3>{{ editingCourse ? t('learning', 'Edit Course') : t('learning', 'Create Course') }}</h3>

				<NcNoteCard v-if="modalError" type="error" class="modal-error">
					{{ modalError }}
				</NcNoteCard>

				<div class="form-group">
					<label for="course-title">{{ t('learning', 'Title') }} *</label>
					<NcTextField id="course-title"
						v-model="formData.title"
						:label="t('learning', 'Course title')"
						:error="!formData.title && formSubmitted"
						:disabled="saving" />
					<p v-if="!formData.title && formSubmitted" class="field-error">
						{{ t('learning', 'Title is required') }}
					</p>
				</div>

				<div class="form-group">
					<label for="course-description">{{ t('learning', 'Description') }}</label>
					<textarea id="course-description"
						v-model="formData.description"
						class="course-textarea"
						:placeholder="t('learning', 'Optional course description...')"
						:disabled="saving"
						rows="4" />
				</div>

				<div v-if="userRole === 'instructor'" class="form-group">
					<label for="course-group">{{ t('learning', 'NC Group ID') }}</label>
					<NcTextField id="course-group"
						v-model="formData.ncGroupId"
						:label="t('learning', 'Nextcloud group for auto-enrollment')"
						:disabled="saving" />
					<p class="field-hint">
						{{ t('learning', 'Optional. Members of this Nextcloud group will be auto-enrolled.') }}
					</p>
				</div>

				<div class="modal-actions">
					<NcButton type="tertiary"
						:disabled="saving"
						@click="closeModal">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="primary"
						:disabled="saving"
						@click="saveCourse">
						<template v-if="saving">
							{{ t('learning', 'Saving...') }}
						</template>
						<template v-else>
							{{ editingCourse ? t('learning', 'Save Changes') : t('learning', 'Create') }}
						</template>
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Delete confirmation modal -->
		<NcModal v-if="showDeleteModal" @close="showDeleteModal = false" @closing="showDeleteModal = false" size="small">
			<div class="modal-content">
				<h3>{{ t('learning', 'Delete Course') }}</h3>
				<p>{{ t('learning', 'Are you sure you want to delete "{title}"? This action cannot be undone.', { title: deletingCourse ? deletingCourse.title : '' }) }}</p>
				<div class="modal-actions">
					<NcButton type="tertiary"
						:disabled="saving"
						@click="showDeleteModal = false">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="error"
						:disabled="saving"
						@click="deleteCourse">
						<template v-if="saving">
							{{ t('learning', 'Deleting...') }}
						</template>
						<template v-else>
							{{ t('learning', 'Delete') }}
						</template>
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showSuccess } from '@nextcloud/dialogs'
import hintMixin from '../hintMixin.js'

export default {
	name: 'CourseList',

	components: {
		NcButton,
		NcEmptyContent,
		NcModal,
		NcTextField,
		NcActions,
		NcActionButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	mixins: [hintMixin],

	props: {
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
			saving: false,
			error: '',
			modalError: '',
			ownCourses: [],
			enrolledCourses: [],
			showCreateModal: false,
			showDeleteModal: false,
			editingCourse: null,
			deletingCourse: null,
			formSubmitted: false,
			formData: {
				title: '',
				description: '',
				ncGroupId: '',
			},
		}
	},

	computed: {
		emptyStateTitle() {
			if (this.userRole === 'instructor') {
				return t('learning', 'No courses yet')
			}
			return t('learning', 'No enrolled courses')
		},
		emptyStateDescription() {
			if (this.userRole === 'instructor') {
				return t('learning', 'Create your first course to get started.')
			}
			return t('learning', 'You are not enrolled in any courses yet. Your instructor will add you to a course — check back soon!')
		},
	},

	mounted() {
		this.fetchCourses()
	},

	methods: {
		async fetchCourses() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/learning/api/courses')
				const response = await axios.get(url)
				this.ownCourses = response.data.own || []
				this.enrolledCourses = response.data.enrolled || []
			} catch (err) {
				console.error('Failed to fetch courses:', err)
				this.error = t('learning', 'Failed to load courses. Please try again.')
			} finally {
				this.loading = false
			}
		},

		truncateDescription(text) {
			if (!text) {
				return ''
			}
			const maxLength = 120
			if (text.length <= maxLength) {
				return text
			}
			return text.substring(0, maxLength) + '...'
		},

		openCreateModal() {
			this.editingCourse = null
			this.formData = { title: '', description: '', ncGroupId: '' }
			this.formSubmitted = false
			this.modalError = ''
			this.showCreateModal = true
		},

		editCourse(course) {
			this.editingCourse = course
			this.formData = {
				title: course.title || '',
				description: course.description || '',
				ncGroupId: course.nc_group_id || '',
			}
			this.formSubmitted = false
			this.modalError = ''
			this.showCreateModal = true
		},

		closeModal() {
			// Keep close behavior robust across nc-vue modal event variants
			// and avoid stale form state when reopening quickly.
			this.showCreateModal = false
			this.editingCourse = null
			this.formSubmitted = false
			this.modalError = ''
			this.formData = { title: '', description: '', ncGroupId: '' }
		},

		async saveCourse() {
			this.formSubmitted = true
			if (!this.formData.title.trim()) {
				return
			}

			this.saving = true
			this.modalError = ''

			try {
				const payload = {
					title: this.formData.title.trim(),
				}
				if (this.formData.description.trim()) {
					payload.description = this.formData.description.trim()
				}
				if (this.formData.ncGroupId.trim()) {
					payload.ncGroupId = this.formData.ncGroupId.trim()
				}

				if (this.editingCourse) {
					const url = generateUrl('/apps/learning/api/courses/{id}', { id: this.editingCourse.id })
					await axios.put(url, payload)
					showSuccess(t('learning', 'Course updated'))
				} else {
					const url = generateUrl('/apps/learning/api/courses')
					await axios.post(url, payload)
					showSuccess(t('learning', 'Course created'))
				}

				this.closeModal()
				await this.fetchCourses()
			} catch (err) {
				console.error('Failed to save course:', err)
				const message = err.response?.data?.error || err.response?.data?.message
				this.modalError = message || t('learning', 'Failed to save course. Please try again.')
			} finally {
				this.saving = false
			}
		},

		async toggleStatus(course) {
			const newStatus = course.status === 'active' ? 'archived' : 'active'
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}', { id: course.id })
				await axios.put(url, { status: newStatus })
				showSuccess(t('learning', newStatus === 'active' ? 'Course activated' : 'Course archived'))
				await this.fetchCourses()
			} catch (err) {
				console.error('Failed to update course status:', err)
				this.error = t('learning', 'Failed to update course status.')
			}
		},

		confirmDeleteCourse(course) {
			this.deletingCourse = course
			this.showDeleteModal = true
		},

		async deleteCourse() {
			if (!this.deletingCourse) {
				return
			}

			this.saving = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{id}', { id: this.deletingCourse.id })
				await axios.delete(url)
				showSuccess(t('learning', 'Course deleted'))
				this.showDeleteModal = false
				this.deletingCourse = null
				await this.fetchCourses()
			} catch (err) {
				console.error('Failed to delete course:', err)
				this.error = t('learning', 'Failed to delete course.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.course-list {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.course-list-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 24px;
}

.course-list-header h3 {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
	color: var(--color-main-text);
}

.course-list-error {
	margin-bottom: 16px;
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

.course-section {
	margin-bottom: 32px;
}

.course-section h4 {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
	margin: 0 0 14px 0;
}

.course-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 16px;
}

.course-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px;
	cursor: pointer;
	transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.course-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px color-mix(in srgb, var(--color-main-text) 8%, transparent);
	border-color: var(--color-primary-element);
}

.course-card-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 8px;
}

.course-title {
	font-size: 1.1em;
	font-weight: 600;
	color: var(--color-main-text);
	word-break: break-word;
	flex: 1;
}

.course-card-actions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
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
	background: color-mix(in srgb, var(--color-success) 40%, transparent);
	color: var(--color-success);
}

.status-badge.archived {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.role-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: capitalize;
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
	white-space: nowrap;
}

.course-description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
	word-break: break-word;
}

.course-meta {
	display: flex;
	gap: 16px;
	margin-top: auto;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.meta-item {
	font-size: 11px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	letter-spacing: 0.3px;
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

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 6px;
	font-size: 0.9em;
	color: var(--color-main-text);
}

.course-textarea {
	width: 100%;
	padding: 10px 12px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	resize: vertical;
	font-family: inherit;
	font-size: 0.95em;
	color: var(--color-main-text);
	background: var(--color-main-background);
	box-sizing: border-box;
}

.course-textarea:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.course-textarea:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.field-error {
	color: var(--color-error);
	font-size: 0.8em;
	margin: 4px 0 0 0;
}

.field-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	margin: 4px 0 0 0;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

/* Responsive adjustments */
@media (max-width: 768px) {
	.course-list {
		padding: 12px;
	}

	.course-grid {
		grid-template-columns: 1fr;
	}

	.course-list-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 12px;
	}

	.modal-content {
		padding: 16px;
		min-width: unset;
	}
}

@media (prefers-reduced-motion: reduce) {
	.course-card:hover { transform: none; }
}

.onboarding-hint {
	margin-bottom: 16px;
}
</style>
