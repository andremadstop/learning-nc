<template>
	<div class="course-tab-kommunikation">
		<div class="kommunikation-subnav" role="tablist" :aria-label="t('learning', 'Kommunikation Bereiche')">
			<button
				v-for="tab in visibleSubTabs"
				:key="tab.id"
				class="kommunikation-pill"
				:class="{ active: currentSubTab === tab.id }"
				@click="selectSubTab(tab.id)">
				{{ tab.label }}
			</button>
		</div>

		<!-- Announcements (instructor only) -->
		<div v-if="currentSubTab === 'announcements' && isInstructor" class="tab-content announcements-section">
			<div class="announcement-form">
				<h3>{{ t('learning', 'New announcement') }}</h3>
				<input v-model="newAnnouncementTitle" type="text" :placeholder="t('learning', 'Title')" class="nc-input announcement-input" />
				<textarea v-model="newAnnouncementBody" :placeholder="t('learning', 'Message...')" class="nc-textarea" rows="3" />
				<NcButton type="primary" :disabled="savingAnnouncement || !newAnnouncementTitle.trim()" @click="createAnnouncement">
					{{ t('learning', 'Publish') }}
				</NcButton>
			</div>
			<div class="announcements-list">
				<div v-if="announcements.length === 0" class="empty-hint">{{ t('learning', 'No announcements yet.') }}</div>
				<div v-for="a in announcements" :key="a.id" class="announcement-item">
					<div class="announcement-header">
						<strong>{{ a.title }}</strong>
						<NcButton type="tertiary" @click="deleteAnnouncement(a.id)">{{ t('learning', 'Delete') }}</NcButton>
					</div>
					<p class="announcement-body">{{ a.body }}</p>
					<small class="announcement-date">{{ formatDate(a.created_at) }}</small>
				</div>
			</div>
		</div>

		<!-- Feed (both roles) -->
		<div v-if="currentSubTab === 'feed'" class="tab-content">
			<CourseFeed :course-id="courseId" />
		</div>

		<!-- Requests (instructor only) -->
		<div v-if="currentSubTab === 'requests' && isInstructor" class="tab-content requests-section">
			<div v-if="loadingRequests" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
			<div v-else-if="courseTickets.length === 0" class="empty-hint">
				{{ t('learning', 'No open requests for this course.') }}
			</div>
			<div v-else class="tickets-list-cd">
				<div v-for="ticket in courseTickets" :key="ticket.id" class="ticket-item-cd">
					<div class="ticket-header-cd">
						<span class="ticket-subject-cd">{{ ticket.subject }}</span>
						<span class="ticket-status-cd" :class="'status-' + ticket.status">{{ ticket.status }}</span>
					</div>
					<p class="ticket-message-cd">{{ ticket.message }}</p>
					<div v-if="ticket.status === 'open'" class="ticket-reply-cd">
						<textarea v-model="ticketReplies[ticket.id]" :placeholder="t('learning', 'Your answer...')" class="nc-textarea" rows="3" />
						<NcButton type="primary" @click="answerTicket(ticket.id)">{{ t('learning', 'Send answer') }}</NcButton>
					</div>
					<div v-if="ticket.answer_text" class="ticket-answer-cd">
						<strong>{{ t('learning', 'Answer:') }}</strong> {{ ticket.answer_text }}
					</div>
				</div>
			</div>
		</div>

		<!-- Buddies (student only) -->
		<div v-if="currentSubTab === 'buddies' && !isInstructor" class="tab-content">
			<BuddyMatching :course-id="courseId" />
		</div>

		<!-- Schwarm (student only) -->
		<div v-if="currentSubTab === 'schwarm' && !isInstructor" class="tab-content">
			<StudentKnowledgeContribute :course-id="courseId" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import CourseFeed from './CourseFeed.vue'
import BuddyMatching from './BuddyMatching.vue'
import StudentKnowledgeContribute from './StudentKnowledgeContribute.vue'

export default {
	name: 'CourseTabKommunikation',

	components: {
		NcButton,
		CourseFeed,
		BuddyMatching,
		StudentKnowledgeContribute,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		course: {
			type: Object,
			default: null,
		},
		userRole: {
			type: String,
			required: true,
		},
		activeTab: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			currentSubTab: '',
			announcements: [],
			newAnnouncementTitle: '',
			newAnnouncementBody: '',
			savingAnnouncement: false,
			loadingRequests: false,
			courseTickets: [],
			ticketReplies: {},
		}
	},

	computed: {
		isInstructor() {
			return this.course && this.course.is_instructor
		},
		visibleSubTabs() {
			if (this.isInstructor) {
				return [
					{ id: 'announcements', label: t('learning', 'Ankuendigungen') },
					{ id: 'feed', label: t('learning', 'Feed') },
					{ id: 'requests', label: t('learning', 'Anfragen') },
				]
			}
			return [
				{ id: 'feed', label: t('learning', 'Feed') },
				{ id: 'buddies', label: t('learning', 'Lernpartner') },
				{ id: 'schwarm', label: t('learning', 'Schwarm') },
			]
		},
	},

	watch: {
		activeTab: {
			immediate: true,
			handler(tab) {
				this.syncFromActiveTab(tab)
			},
		},
	},

	methods: {
		t,

		defaultSubTab() {
			return this.visibleSubTabs[0]?.id || 'feed'
		},

		syncFromActiveTab(tab) {
			const validIds = this.visibleSubTabs.map((s) => s.id)
			if (validIds.includes(tab)) {
				this.currentSubTab = tab
			} else {
				this.currentSubTab = this.defaultSubTab()
			}
			this.lazyLoad(this.currentSubTab)
		},

		selectSubTab(tabId) {
			this.currentSubTab = tabId
			this.$emit('tab-change', tabId)
			this.lazyLoad(tabId)
		},

		lazyLoad(tab) {
			if (tab === 'announcements' && this.isInstructor) {
				this.fetchAnnouncements()
			}
			if (tab === 'requests' && this.isInstructor) {
				this.fetchCourseTickets()
			}
		},

		formatDate(timestamp) {
			if (!timestamp) return ''
			try {
				const ts = typeof timestamp === 'number' ? timestamp * 1000 : Date.parse(timestamp)
				const date = new Date(ts)
				if (isNaN(date.getTime())) return String(timestamp)
				return date.toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				})
			} catch {
				return String(timestamp)
			}
		},

		async fetchAnnouncements() {
			if (!this.course) return
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/announcements`))
				this.announcements = res.data.announcements || res.data || []
			} catch (e) {
				console.error('Failed to load announcements', e)
			}
		},

		async createAnnouncement() {
			if (!this.newAnnouncementTitle.trim()) return
			this.savingAnnouncement = true
			try {
				const res = await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/announcements`), {
					title: this.newAnnouncementTitle,
					body: this.newAnnouncementBody,
				})
				this.announcements.unshift(res.data.announcement || res.data)
				this.newAnnouncementTitle = ''
				this.newAnnouncementBody = ''
			} catch (e) {
				console.error('Failed to create announcement', e)
			} finally {
				this.savingAnnouncement = false
			}
		},

		async deleteAnnouncement(id) {
			try {
				await axios.delete(generateUrl(`/apps/learning/api/courses/${this.courseId}/announcements/${id}`))
				this.announcements = this.announcements.filter(a => a.id !== id)
			} catch (e) {
				console.error('Failed to delete announcement', e)
			}
		},

		async fetchCourseTickets() {
			if (!this.course) return
			this.loadingRequests = true
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/support-tickets`))
				this.courseTickets = res.data.tickets || res.data || []
			} catch (e) {
				console.error('Failed to load course tickets', e)
			} finally {
				this.loadingRequests = false
			}
		},

		async answerTicket(ticketId) {
			const answer = this.ticketReplies[ticketId]
			if (!answer || !answer.trim()) return
			try {
				await axios.post(generateUrl(`/apps/learning/api/settings/admin/support-tickets/${ticketId}/answer`), {
					answerText: answer,
				})
				const ticket = this.courseTickets.find(t => t.id === ticketId)
				if (ticket) {
					ticket.status = 'answered'
					ticket.answer_text = answer
				}
				this.$delete(this.ticketReplies, ticketId)
			} catch (e) {
				console.error('Failed to answer ticket', e)
			}
		},
	},
}
</script>

<style scoped>
.course-tab-kommunikation { padding: 0; }

/* Sub-nav pills */
.kommunikation-subnav {
	display: flex;
	gap: 6px;
	padding: 8px 0 16px;
	flex-wrap: wrap;
}
.kommunikation-pill {
	padding: 6px 16px;
	border-radius: 20px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9em;
	transition: background 0.15s, border-color 0.15s;
}
.kommunikation-pill:hover { background: var(--color-background-hover); }
.kommunikation-pill.active {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-color: var(--color-primary);
}

/* Announcements */
.announcements-section {}
.announcement-form { margin-bottom: 24px; padding: 16px; background: var(--color-background-dark); border-radius: 8px; }
.announcement-form h3 { margin: 0 0 12px 0; font-size: 1.1em; font-weight: 600; }
.announcement-input { width: 100%; box-sizing: border-box; margin-bottom: 8px; }
.announcements-list { display: flex; flex-direction: column; gap: 8px; }
.announcement-item { padding: 12px; border: 1px solid var(--color-border); border-radius: 8px; }
.announcement-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.announcement-body { color: var(--color-text-maxcontrast); margin: 4px 0; }
.announcement-date { color: var(--color-text-maxcontrast); font-size: 0.85em; }

/* Requests */
.requests-section {}
.tickets-list-cd { display: flex; flex-direction: column; gap: 12px; }
.ticket-item-cd { border: 1px solid var(--color-border); border-radius: 8px; padding: 12px; }
.ticket-header-cd { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.ticket-subject-cd { font-weight: 600; }
.ticket-status-cd { font-size: 0.85em; padding: 2px 8px; border-radius: 4px; }
.ticket-status-cd.status-open { background: color-mix(in srgb, var(--color-warning) 15%, transparent); color: var(--color-warning); }
.ticket-status-cd.status-answered { background: color-mix(in srgb, var(--color-success) 15%, transparent); color: var(--color-success); }
.ticket-message-cd { color: var(--color-text-maxcontrast); margin-bottom: 8px; }
.ticket-reply-cd { margin-top: 8px; display: flex; flex-direction: column; gap: 8px; }
.ticket-answer-cd { margin-top: 8px; padding: 8px; background: var(--color-background-dark); border-radius: 4px; font-size: 0.9em; }
</style>
