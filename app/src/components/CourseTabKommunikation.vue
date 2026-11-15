<template>
	<div class="course-tab-kommunikation">
		<div class="kommunikation-subnav" role="tablist" @keydown="moveTablistFocus" :aria-label="t('learning', 'Kommunikation Bereiche')">
			<button
				v-for="tab in visibleSubTabs"
				:key="tab.id"
				type="button"
				class="kommunikation-pill"
				:class="{ active: currentSubTab === tab.id }"
				role="tab"
				:aria-selected="currentSubTab === tab.id ? 'true' : 'false'"
				@click="selectSubTab(tab.id)">
				{{ tab.label }}
				<!-- GitHub #59: how many questions wait for an answer -->
				<span v-if="tab.id === 'requests' && openQuestionsCount > 0" class="kommunikation-pill-count">
					<span aria-hidden="true">{{ openQuestionsCount }}</span>
					<span class="hidden-visually">{{ openQuestionsLabel }}</span>
				</span>
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

		<!-- Questions from learners (instructor only): course questions sent from the learners'
			"Frage stellen" tab (#59) or through the help assistant. GitHub #40: named for what it
			holds, and the empty state says where the questions come from. -->
		<div v-if="currentSubTab === 'requests' && isInstructor" class="tab-content requests-section">
			<!-- GitHub #59: archived questions are hidden from the list and can be brought back -->
			<div class="requests-toolbar">
				<NcButton variant="tertiary" class="requests-archive-toggle" @click="toggleArchive">
					{{ showArchived ? t('learning', 'Aktuelle Fragen anzeigen') : t('learning', 'Archivierte Fragen anzeigen') }}
				</NcButton>
			</div>
			<p v-if="showArchived" class="requests-archive-hint">
				{{ t('learning', 'Archivierte Fragen sind aus der Liste ausgeblendet. Die lernende Person sieht ihre Frage und die Antwort weiterhin.') }}
			</p>
			<div v-if="loadingRequests" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
			<p v-else-if="showArchived && courseTickets.length === 0" class="empty-hint">
				{{ t('learning', 'Keine archivierten Fragen.') }}
			</p>
			<div v-else-if="courseTickets.length === 0" class="empty-hint requests-empty">
				<p>{{ t('learning', 'Noch keine Fragen von Lernenden zu diesem Kurs.') }}</p>
				<p>{{ t('learning', 'Hier erscheinen Fragen zu diesem Kurs, die Lernende dir unter „Frage stellen“ im Bereich Kommunikation oder über den Hilfe-Assistenten VirtuProf schicken.') }}</p>
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
					<div class="ticket-manage-cd">
						<NcButton v-if="ticket.status === 'archived'"
							variant="secondary"
							:disabled="!!managingTickets[ticket.id]"
							@click="setArchived(ticket, false)">
							{{ t('learning', 'Wiederherstellen') }}
						</NcButton>
						<NcButton v-else
							variant="secondary"
							:disabled="!!managingTickets[ticket.id]"
							@click="setArchived(ticket, true)">
							{{ t('learning', 'Archivieren') }}
						</NcButton>
						<NcButton variant="tertiary"
							:disabled="!!managingTickets[ticket.id]"
							@click="confirmTicket = ticket">
							{{ t('learning', 'Löschen') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<AccessibleDialog v-if="confirmTicket"
			:name="t('learning', 'Frage löschen')"
			@closing="cancelDeleteTicket">
			<p>{{ t('learning', 'Möchtest du die Frage „{subject}“ wirklich löschen? Frage und Antwort sind danach auch für die lernende Person weg. Das lässt sich nicht rückgängig machen.', { subject: confirmTicket.subject }, undefined, { escape: false, sanitize: false }) }}</p>
			<template #actions>
				<NcButton variant="tertiary" :disabled="deletingTicket" @click="cancelDeleteTicket">
					{{ t('learning', 'Nicht löschen') }}
				</NcButton>
				<NcButton variant="error" :disabled="deletingTicket" @click="deleteTicket">
					{{ t('learning', 'Löschen') }}
				</NcButton>
			</template>
		</AccessibleDialog>

		<!-- GitHub #59: questions to the instructors (learners, also without VirtuProf) -->
		<div v-if="currentSubTab === 'my-questions' && !isInstructor" class="tab-content">
			<CourseLearnerQuestions :course-id="courseId" />
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
import { nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { showError } from '@nextcloud/dialogs'
import AccessibleDialog from './AccessibleDialog.vue'
import CourseFeed from './CourseFeed.vue'
import CourseLearnerQuestions from './CourseLearnerQuestions.vue'
import BuddyMatching from './BuddyMatching.vue'
import { buddiesEnabled, kommunikationTabIds } from '../utils/courseView.js'
import StudentKnowledgeContribute from './StudentKnowledgeContribute.vue'
import { moveTablistFocus } from '../utils/tablistKeydown.js'

export default {
	name: 'CourseTabKommunikation',

	components: {
		NcButton,
		AccessibleDialog,
		CourseFeed,
		CourseLearnerQuestions,
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
		// GitHub #59: unanswered questions of the course (CourseDetail loads it for the badge)
		openQuestionsCount: {
			type: Number,
			default: 0,
		},
	},

	emits: ['tab-change', 'open-questions-count'],

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
			showArchived: false,
			managingTickets: {},
			confirmTicket: null,
			deletingTicket: false,
		}
	},

	computed: {
		// GitHub #35: course membership minus the student view, computed once in CourseDetail.
		isInstructor() {
			return this.actsAsInstructor
		},
		openQuestionsLabel() {
			return t('learning', 'Unbeantwortete Fragen: {count}', { count: this.openQuestionsCount })
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
				'my-questions': t('learning', 'Frage stellen'),
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
		moveTablistFocus,
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
			if (status === 'archived') return t('learning', 'Archiviert')
			return status
		},

		toggleArchive() {
			this.showArchived = !this.showArchived
			this.fetchCourseTickets()
		},

		// GitHub #59: the server's count, after every change that can move it
		reportOpenCount(count) {
			if (Number.isInteger(count)) {
				this.$emit('open-questions-count', count)
			}
		},

		async refreshOpenCount() {
			try {
				const res = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/support-tickets/open-count', { courseId: this.courseId }))
				this.reportOpenCount(res.data?.open_count)
			} catch (e) {
				// the badge is a hint; the list itself is correct
				console.error('Failed to refresh the open question count', e)
			}
		},

		async setArchived(ticket, archived) {
			if (this.managingTickets[ticket.id]) return
			this.managingTickets[ticket.id] = true
			try {
				const action = archived ? 'archive' : 'restore'
				await axios.post(generateUrl('/apps/learning/api/courses/{courseId}/support-tickets/{ticketId}/' + action, { courseId: this.courseId, ticketId: ticket.id }))
				// it leaves the list it was in: the inbox when archived, the archive when restored
				this.removeTicket(ticket)
				this.refreshOpenCount()
			} catch (e) {
				console.error('Failed to change the archive state of a question', e)
				showError(archived
					? t('learning', 'Die Frage konnte nicht archiviert werden.')
					: t('learning', 'Die Frage konnte nicht wiederhergestellt werden.'))
			} finally {
				delete this.managingTickets[ticket.id]
			}
		},

		// The clicked button leaves with its question; keyboard focus moves to the question that
		// takes its place (or the one above), else to the archive switch, not to the page body.
		async removeTicket(ticket) {
			const index = this.courseTickets.findIndex((x) => x.id === ticket.id)
			this.courseTickets = this.courseTickets.filter((x) => x.id !== ticket.id)
			await nextTick()
			const items = this.$el?.querySelectorAll?.('.ticket-item-cd .ticket-manage-cd') || []
			const next = items[Math.min(Math.max(index, 0), items.length - 1)]
			const target = next?.querySelector('button') || this.$el?.querySelector?.('.requests-archive-toggle')
			target?.focus?.()
		},

		cancelDeleteTicket() {
			if (!this.deletingTicket) {
				this.confirmTicket = null
			}
		},

		async deleteTicket() {
			const ticket = this.confirmTicket
			if (!ticket || this.deletingTicket) return
			this.deletingTicket = true
			try {
				await axios.delete(generateUrl('/apps/learning/api/courses/{courseId}/support-tickets/{ticketId}', { courseId: this.courseId, ticketId: ticket.id }))
				this.confirmTicket = null
				this.removeTicket(ticket)
				this.refreshOpenCount()
			} catch (e) {
				console.error('Failed to delete a question', e)
				showError(t('learning', 'Die Frage konnte nicht gelöscht werden.'))
			} finally {
				this.deletingTicket = false
			}
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
				const archived = this.showArchived
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/support-tickets`), {
					params: archived ? { archived: 1 } : {},
				})
				// a late answer for the other list must not replace the one now shown
				if (archived !== this.showArchived) return
				this.courseTickets = res.data.tickets || res.data || []
				this.reportOpenCount(res.data?.open_count)
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
				this.refreshOpenCount()
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
/* GitHub #59: unanswered questions; inverted on the active pill so it stays readable */
.kommunikation-pill-count {
	display: inline-block;
	min-width: 1.5em;
	margin-inline-start: 6px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--color-primary-element, var(--color-primary));
	color: var(--color-primary-element-text, var(--color-primary-text));
	font-size: 0.85em;
	font-weight: 600;
	line-height: 1.5;
	text-align: center;
}
.kommunikation-pill.active .kommunikation-pill-count {
	background: var(--color-primary-text);
	color: var(--color-primary);
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
.ticket-status-cd.status-open { background: var(--color-warning); color: var(--color-warning-text, var(--color-warning)); }
.ticket-status-cd.status-answered { background: var(--color-success); color: var(--color-success-text, var(--color-success)); }
.ticket-message-cd { color: var(--color-text-maxcontrast); margin-bottom: 8px; }
.ticket-reply-cd { margin-top: 8px; display: flex; flex-direction: column; gap: 8px; }
.requests-empty p { margin: 0 0 4px; }
.requests-toolbar { display: flex; justify-content: flex-end; margin-bottom: 8px; }
.requests-archive-hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 0 0 12px; }
.ticket-manage-cd { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; justify-content: flex-end; }
.ticket-status-cd.status-archived { background: var(--color-background-darker); color: var(--color-main-text); }
.ticket-answer-cd { margin-top: 8px; padding: 8px; background: var(--color-background-dark); border-radius: 4px; font-size: 0.9em; }
</style>
