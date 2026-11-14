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

		<!-- Announcements (instructor only). GitHub #40: only the form; published announcements
			are listed, and deleted, in the feed. -->
		<div v-if="currentSubTab === 'announcements' && isInstructor" class="tab-content announcements-section">
			<div class="announcement-form">
				<h3>{{ t('learning', 'New announcement') }}</h3>
				<input v-model="newAnnouncementTitle" type="text" :placeholder="t('learning', 'Title')" class="nc-input announcement-input" />
				<textarea v-model="newAnnouncementBody" :placeholder="t('learning', 'Message...')" class="nc-textarea announcement-textarea" rows="6" />
				<p class="announcement-hint">{{ t('learning', 'Veröffentlichte Ankündigungen erscheinen im Feed. Dort kannst du sie auch bearbeiten oder löschen.') }}</p>
				<NcButton variant="primary" :disabled="savingAnnouncement || !newAnnouncementTitle.trim()" @click="createAnnouncement">
					{{ t('learning', 'Publish') }}
				</NcButton>
			</div>
		</div>

		<!-- Feed (both roles) -->
		<div v-if="currentSubTab === 'feed'" class="tab-content">
			<CourseFeed :course-id="courseId" :can-manage="isInstructor" />
		</div>

		<!-- Questions from learners (instructor only): course questions sent through the help
			assistant. GitHub #40: named for what it holds, and the empty state says where the
			questions come from. -->
		<div v-if="currentSubTab === 'requests' && isInstructor" class="tab-content requests-section">
			<div v-if="loadingRequests" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
			<div v-else-if="courseTickets.length === 0" class="empty-hint requests-empty">
				<p>{{ t('learning', 'Noch keine Fragen von Lernenden zu diesem Kurs.') }}</p>
				<p>{{ t('learning', 'Hier erscheinen Fragen zu diesem Kurs, die Lernende dir über den Hilfe-Assistenten VirtuProf schicken.') }}</p>
			</div>
			<div v-else class="tickets-list-cd">
				<div v-for="ticket in courseTickets" :key="ticket.id" class="ticket-item-cd">
					<div class="ticket-header-cd">
						<span class="ticket-subject-cd">{{ ticket.subject }}</span>
						<span class="ticket-status-cd" :class="'status-' + ticket.status">{{ ticketStatusLabel(ticket.status) }}</span>
					</div>
					<p class="ticket-message-cd">{{ ticket.message }}</p>
					<div v-if="ticket.status === 'open'" class="ticket-reply-cd">
						<textarea v-model="ticketReplies[ticket.id]" :placeholder="t('learning', 'Your answer...')" class="nc-textarea ticket-reply-textarea" rows="4" />
						<NcButton variant="primary" :disabled="!!answeringTickets[ticket.id]" @click="answerTicket(ticket.id)">{{ t('learning', 'Send answer') }}</NcButton>
					</div>
					<div v-if="ticket.answer_text" class="ticket-answer-cd">
						<strong>{{ t('learning', 'Answer:') }}</strong> {{ ticket.answer_text }}
					</div>
				</div>
			</div>
		</div>

		<!-- Buddies (student only) -->
		<div v-if="currentSubTab === 'buddies' && !isInstructor && buddiesOn" class="tab-content">
			<BuddyMatching :course-id="courseId" />
		</div>

		<!-- Schwarm (student only) -->
		<div v-if="currentSubTab === 'schwarm' && !isInstructor && aiEnabled" class="tab-content">
			<StudentKnowledgeContribute :course-id="courseId" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { showError } from '@nextcloud/dialogs'
import CourseFeed from './CourseFeed.vue'
import BuddyMatching from './BuddyMatching.vue'
import { buddiesEnabled, kommunikationTabIds } from '../utils/courseView.js'
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
		// GitHub #35: CourseDetail's single instructor gate (membership, student view off).
		actsAsInstructor: { type: Boolean, default: false },
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
		// GitHub #35: the swarm feeds the AI assistant's course knowledge, and its moderation lives
		// in the knowledge tab, which is left out while the admin has AI switched off. Without AI a
		// contribution would wait in a queue nobody can open.
		aiEnabled: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			currentSubTab: '',
			newAnnouncementTitle: '',
			newAnnouncementBody: '',
			savingAnnouncement: false,
			loadingRequests: false,
			courseTickets: [],
			ticketReplies: {},
			answeringTickets: {},
		}
	},

	computed: {
		// GitHub #35: course membership minus the student view, computed once in CourseDetail.
		isInstructor() {
			return this.actsAsInstructor
		},
		buddiesOn() {
			return buddiesEnabled(this.course)
		},
		// GitHub #40: the ids come from the helper CourseDetail routes with, so both agree.
		visibleSubTabs() {
			const labels = {
				announcements: t('learning', 'Ankuendigungen'),
				feed: t('learning', 'Feed'),
				requests: t('learning', 'Fragen von Lernenden'),
				buddies: t('learning', 'Lernpartner'),
				schwarm: t('learning', 'Schwarm'),
			}
			return kommunikationTabIds(this.course, { actsAsInstructor: this.isInstructor, aiEnabled: this.aiEnabled })
				.map((id) => ({ id, label: labels[id] }))
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
			if (tab === 'requests' && this.isInstructor) {
				this.fetchCourseTickets()
			}
		},

		ticketStatusLabel(status) {
			if (status === 'open') return t('learning', 'Offen (Status einer Frage)')
			if (status === 'answered') return t('learning', 'Answered')
			return status
		},

		async createAnnouncement() {
			if (!this.newAnnouncementTitle.trim()) return
			this.savingAnnouncement = true
			try {
				await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/announcements`), {
					title: this.newAnnouncementTitle,
					body: this.newAnnouncementBody,
				})
				this.newAnnouncementTitle = ''
				this.newAnnouncementBody = ''
				// GitHub #40: the published announcement is shown where everyone reads it
				this.selectSubTab('feed')
			} catch (e) {
				// GitHub #40: a refused or failed publish used to leave only a console line
				console.error('Failed to create announcement', e)
				showError(t('learning', 'Die Ankündigung konnte nicht veröffentlicht werden.'))
			} finally {
				this.savingAnnouncement = false
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
				showError(t('learning', 'Die Fragen von Lernenden konnten nicht geladen werden.'))
			} finally {
				this.loadingRequests = false
			}
		},

		async answerTicket(ticketId) {
			const answer = this.ticketReplies[ticketId]
			if (!answer || !answer.trim() || this.answeringTickets[ticketId]) return
			this.answeringTickets[ticketId] = true
			try {
				await axios.post(generateUrl(`/apps/learning/api/settings/admin/support-tickets/${ticketId}/answer`), {
					answerText: answer,
				})
				const ticket = this.courseTickets.find(t => t.id === ticketId)
				if (ticket) {
					ticket.status = 'answered'
					ticket.answer_text = answer
				}
				delete this.ticketReplies[ticketId]
			} catch (e) {
				// GitHub #40: the typed answer stays in the field, and the instructor learns it was not sent
				console.error('Failed to answer ticket', e)
				showError(t('learning', 'Die Antwort konnte nicht gesendet werden.'))
			} finally {
				delete this.answeringTickets[ticketId]
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
.announcement-hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 4px 0 12px; }

/* GitHub #40: Nextcloud core gives every textarea width: 130px; flex stretch cannot override an
   explicit width, so both fields set their own. */
.announcement-textarea,
.ticket-reply-textarea {
	display: block;
	width: 100%;
	max-width: 100%;
	min-height: 8em;
	box-sizing: border-box;
	margin-inline: 0;
	resize: vertical;
}
.ticket-reply-textarea { min-height: 6em; }

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
.requests-empty p { margin: 0 0 4px; }
.ticket-answer-cd { margin-top: 8px; padding: 8px; background: var(--color-background-dark); border-radius: 4px; font-size: 0.9em; }
</style>
