<template>
	<div class="course-learner-questions">
		<!-- GitHub #59: learners ask the instructors here, with VirtuProf switched off as well -->
		<section v-if="canAsk" class="question-form">
			<h3>{{ t('learning', 'Frage an die Kursleitung') }}</h3>
			<p class="question-hint">
				{{ t('learning', 'Deine Frage geht an die Lehrenden dieses Kurses. Nur sie und du sehen die Frage und die Antwort.') }}
			</p>
			<label class="question-label" for="learner-question-subject">{{ t('learning', 'Betreff (optional)') }}</label>
			<input id="learner-question-subject"
				v-model="subject"
				type="text"
				maxlength="255"
				class="nc-input question-subject">
			<label class="question-label" for="learner-question-message">{{ t('learning', 'Deine Frage') }}</label>
			<textarea id="learner-question-message"
				v-model="message"
				maxlength="5000"
				rows="6"
				class="nc-textarea question-message" />
			<NcButton variant="primary" :disabled="sending || !message.trim()" @click="send">
				{{ t('learning', 'Frage senden') }}
			</NcButton>
		</section>
		<NcNoteCard v-else-if="loaded" type="info" class="question-note">
			{{ t('learning', 'In der Schüler-Ansicht kannst du keine Fragen senden. Die Fragen der Lernenden findest du ohne Schüler-Ansicht unter „Fragen von Lernenden“.') }}
		</NcNoteCard>

		<section class="question-list-section">
			<h3 ref="listHeading" tabindex="-1">{{ t('learning', 'Meine Fragen') }}</h3>
			<div v-if="loading" class="loading-hint">
				{{ t('learning', 'Loading...') }}
			</div>
			<p v-else-if="questions.length === 0" class="empty-hint">
				{{ t('learning', 'Du hast in diesem Kurs noch keine Fragen gestellt.') }}
			</p>
			<ul v-else class="question-list">
				<li v-for="question in questions" :key="question.id" class="question-item">
					<div class="question-header">
						<span class="question-subject">{{ question.subject }}</span>
						<span class="question-status" :class="'status-' + question.status">{{ statusLabel(question.status) }}</span>
					</div>
					<p class="question-message-text">{{ question.message }}</p>
					<div v-if="question.answer_text" class="question-answer">
						<strong>{{ t('learning', 'Answer:') }}</strong> {{ question.answer_text }}
					</div>
					<!-- GitHub #59: a question sent by mistake can be taken back until it is answered -->
					<div v-else-if="question.status === 'open'" class="question-actions">
						<NcButton variant="tertiary" :disabled="withdrawing" @click="confirmQuestion = question">
							{{ t('learning', 'Frage zurückziehen') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</section>

		<AccessibleDialog v-if="confirmQuestion"
			:name="t('learning', 'Frage zurückziehen')"
			@closing="cancelWithdraw">
			<p>{{ t('learning', 'Möchtest du deine Frage „{subject}“ zurückziehen? Sie wird gelöscht, und die Lehrenden sehen sie nicht mehr.', { subject: confirmQuestion.subject }, undefined, { escape: false, sanitize: false }) }}</p>
			<template #actions>
				<NcButton variant="tertiary" :disabled="withdrawing" @click="cancelWithdraw">
					{{ t('learning', 'Nicht zurückziehen') }}
				</NcButton>
				<NcButton variant="error" :disabled="withdrawing" @click="withdraw">
					{{ t('learning', 'Zurückziehen') }}
				</NcButton>
			</template>
		</AccessibleDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { nextTick } from 'vue'
import AccessibleDialog from './AccessibleDialog.vue'

export default {
	name: 'CourseLearnerQuestions',

	components: {
		NcButton,
		NcNoteCard,
		AccessibleDialog,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			subject: '',
			message: '',
			sending: false,
			loading: false,
			loaded: false,
			canAsk: false,
			questions: [],
			confirmQuestion: null,
			withdrawing: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		statusLabel(status) {
			if (status === 'open') return t('learning', 'Offen (Status einer Frage)')
			if (status === 'answered') return t('learning', 'Answered')
			return status
		},

		async load() {
			this.loading = true
			try {
				const res = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/questions/mine', { courseId: this.courseId }))
				this.questions = Array.isArray(res.data?.questions) ? res.data.questions : []
				this.canAsk = res.data?.can_ask === true
				this.loaded = true
			} catch (e) {
				console.error('Failed to load own course questions', e)
				showError(t('learning', 'Deine Fragen konnten nicht geladen werden.'))
			} finally {
				this.loading = false
			}
		},

		cancelWithdraw() {
			if (!this.withdrawing) {
				this.confirmQuestion = null
			}
		},

		async withdraw() {
			const question = this.confirmQuestion
			if (!question || this.withdrawing) return
			this.withdrawing = true
			try {
				await axios.delete(generateUrl('/apps/learning/api/courses/{courseId}/questions/mine/{ticketId}', { courseId: this.courseId, ticketId: question.id }))
				this.questions = this.questions.filter((q) => q.id !== question.id)
				this.confirmQuestion = null
				showSuccess(t('learning', 'Deine Frage wurde zurückgezogen.'))
				// the clicked button is gone with the question; focus goes to the list's heading
				await nextTick()
				this.$refs.listHeading?.focus?.()
			} catch (e) {
				console.error('Failed to withdraw a course question', e)
				if (e?.response?.status === 409) {
					// answered in the meantime: show the answer instead
					this.confirmQuestion = null
					showError(t('learning', 'Die Frage wurde inzwischen beantwortet und kann nicht mehr zurückgezogen werden.'))
					this.load()
				} else {
					showError(t('learning', 'Die Frage konnte nicht zurückgezogen werden.'))
				}
			} finally {
				this.withdrawing = false
			}
		},

		async send() {
			if (!this.message.trim() || this.sending) return
			this.sending = true
			try {
				const res = await axios.post(generateUrl('/apps/learning/api/courses/{courseId}/questions', { courseId: this.courseId }), {
					subject: this.subject,
					message: this.message,
				})
				if (res.data?.question) {
					this.questions.unshift(res.data.question)
				}
				this.subject = ''
				this.message = ''
				showSuccess(t('learning', 'Deine Frage wurde an die Kursleitung gesendet.'))
			} catch (e) {
				// the typed question stays in the form
				console.error('Failed to send course question', e)
				showError(t('learning', 'Die Frage konnte nicht gesendet werden.'))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.course-learner-questions { display: flex; flex-direction: column; gap: 24px; }
.question-form { padding: 16px; background: var(--color-background-dark); border-radius: 8px; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.question-form h3, .question-list-section h3 { margin: 0 0 4px; font-size: 1.1em; font-weight: 600; }
.question-hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 0 0 4px; }
.question-label { font-weight: 600; font-size: 0.9em; }
/* Nextcloud core gives every textarea width: 130px; both fields set their own (as in #40). */
.question-subject,
.question-message {
	display: block;
	width: 100%;
	max-width: 100%;
	box-sizing: border-box;
	margin-inline: 0;
}
.question-message { min-height: 8em; resize: vertical; }
.question-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.question-item { border: 1px solid var(--color-border); border-radius: 8px; padding: 12px; }
.question-header { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
.question-subject { font-weight: 600; overflow-wrap: anywhere; }
.question-status { font-size: 0.85em; padding: 2px 8px; border-radius: 4px; white-space: nowrap; }
.question-status.status-open { background: var(--color-warning); color: var(--color-warning-text, var(--color-warning)); }
.question-status.status-answered { background: var(--color-success); color: var(--color-success-text, var(--color-success)); }
.question-message-text { color: var(--color-text-maxcontrast); margin: 0 0 8px; white-space: pre-wrap; overflow-wrap: anywhere; }
.question-answer { padding: 8px; background: var(--color-background-dark); border-radius: 4px; font-size: 0.9em; white-space: pre-wrap; overflow-wrap: anywhere; }
.question-actions { display: flex; justify-content: flex-end; }
.empty-hint, .loading-hint { color: var(--color-text-maxcontrast); margin: 0; }
</style>
