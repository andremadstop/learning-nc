<template>
	<div class="student-knowledge-contribute">
		<!-- Schwarm-Consent Info-Dialog (einmalig) -->
		<div v-if="showConsentInfo" class="swarm-consent-overlay">
			<div class="swarm-consent-dialog">
				<h3>{{ t('learning', 'Schwarmgedächtnis — Gut zu wissen') }}</h3>
				<div class="swarm-consent-body">
					<p>{{ t('learning', 'Dein Beitrag wird Teil des gemeinsamen Wissens-Pools für diesen Kurs. So funktioniert es:') }}</p>
					<ol class="swarm-consent-list">
						<li><strong>{{ t('learning', 'Prüfung durch den Dozenten') }}</strong>: {{ t('learning', 'Dein Dozent prüft jeden Beitrag, bevor er für die anderen Kursteilnehmer sichtbar wird.') }}</li>
						<li><strong>{{ t('learning', 'Dein Name bleibt geschützt') }}</strong>: {{ t('learning', 'Nur dein Dozent sieht, von wem ein Beitrag stammt. Für andere Studierende bleiben Beiträge anonym.') }}</li>
						<li><strong>{{ t('learning', 'Wissen bleibt erhalten') }}</strong>: {{ t('learning', 'Wenn du deinen Account löschst, wird dein Name entfernt — dein Beitrag bleibt aber anonymisiert bestehen, damit andere weiter davon lernen können.') }}</li>
					</ol>
				</div>
				<button class="material-btn primary swarm-consent-btn" @click="dismissConsentInfo">
					{{ t('learning', 'Verstanden — los geht\'s!') }}
				</button>
			</div>
		</div>

		<!-- Contribution Form -->
		<div class="contribute-form">
			<h4>{{ t('learning', 'Wissen beitragen') }}</h4>
			<p class="contribute-hint">
				{{ t('learning', 'Teile deine Notizen und Erklärungen mit dem Kurs. Beiträge werden vom Dozenten geprüft.') }}
			</p>

			<input
				v-model="title"
				type="text"
				class="contribute-title-input"
				:placeholder="t('learning', 'Titel (z.B. OSI-Modell Zusammenfassung)')"
				:disabled="submitting" />
			<textarea
				v-model="text"
				class="contribute-textarea"
				rows="6"
				:placeholder="t('learning', 'Deine Notizen oder Erklärung hier eingeben...')"
				:disabled="submitting" />

			<div class="contribute-actions">
				<button
					class="material-btn primary"
					:disabled="!canSubmit || submitting"
					@click="submitContribution">
					{{ submitting ? t('learning', 'Sende...') : t('learning', 'Beitrag einreichen') }}
				</button>
				<span class="contribute-counter">
					{{ t('learning', '{n} / {max} Beiträge', { n: contributions.length, max: maxContributions }) }}
				</span>
			</div>

			<div v-if="success" class="success-message">
				{{ success }}
			</div>
			<div v-if="piiWarnings.length" class="warning-message">
				<strong>{{ t('learning', 'Hinweis zu personenbezogenen Daten:') }}</strong>
				{{ t('learning', 'Bitte prüfe deinen Beitrag noch einmal. Er enthält möglicherweise: {types}.', { types: formatPiiWarningTypes(piiWarnings) }) }}
			</div>
			<div v-if="error" class="error-message">
				{{ error }}
			</div>
		</div>

		<!-- My Contributions List -->
		<div class="contributions-list-section">
			<h4>{{ t('learning', 'Meine Beiträge') }}</h4>

			<div v-if="loading" class="loading-container">
				<span class="icon-loading" />
				<p>{{ t('learning', 'Lade...') }}</p>
			</div>

			<div v-else-if="contributions.length === 0" class="empty-state">
				<p>{{ t('learning', 'Du hast noch keine Beiträge eingereicht.') }}</p>
			</div>

			<div v-else class="contributions-list">
				<div v-for="item in contributions"
					:key="item.id"
					class="contribution-item">
					<div class="contribution-header">
						<span class="contribution-title">{{ item.title || item.source_file }}</span>
						<span class="status-badge" :class="statusClass(item.status)">
							{{ statusLabel(item.status) }}
						</span>
					</div>
					<div class="contribution-meta">
						{{ formatDate(item.created_at) }}
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'StudentKnowledgeContribute',

	props: {
		courseId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			title: '',
			text: '',
			contributions: [],
			loading: false,
			submitting: false,
			error: null,
			success: null,
			piiWarnings: [],
			maxContributions: 50,
			showConsentInfo: !localStorage.getItem('learning_swarm_consent_v1'),
		}
	},

	computed: {
		canSubmit() {
			return this.title.trim().length > 0
				&& this.text.trim().length > 0
				&& this.contributions.length < this.maxContributions
		},
	},

	mounted() {
		this.loadContributions()
	},

	methods: {
		t,

		dismissConsentInfo() {
			localStorage.setItem('learning_swarm_consent_v1', '1')
			this.showConsentInfo = false
		},

		async loadContributions() {
			this.loading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge/my-contributions', {
					courseId: this.courseId,
				})
				const response = await axios.get(url)
				this.contributions = response.data || []
			} catch (e) {
				console.error('Failed to load contributions', e)
			} finally {
				this.loading = false
			}
		},

		async submitContribution() {
			if (!this.canSubmit) return
			this.submitting = true
			this.error = null
			this.success = null
			this.piiWarnings = []
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge/contribute', {
					courseId: this.courseId,
				})
				const response = await axios.post(url, {
					title: this.title.trim(),
					text: this.text.trim(),
				})
				const data = response.data
				const chunks = data.chunks || 1
				this.piiWarnings = Array.isArray(data.pii_warnings) ? data.pii_warnings : []
				this.success = t('learning', 'Beitrag eingereicht ({chunks} Chunks). Warte auf Freigabe.', {
					chunks,
				})
				this.title = ''
				this.text = ''
				await this.loadContributions()
				this.autoDismissSuccess()
			} catch (e) {
				this.error = e.response?.data?.error
					|| t('learning', 'Beitrag konnte nicht eingereicht werden.')
			} finally {
				this.submitting = false
			}
		},

		statusClass(status) {
			if (status === 'approved') return 'status-approved'
			if (status === 'rejected') return 'status-rejected'
			return 'status-pending'
		},

		statusLabel(status) {
			if (status === 'approved') return t('learning', 'Freigegeben')
			if (status === 'rejected') return t('learning', 'Abgelehnt')
			return t('learning', 'Ausstehend')
		},

		formatDate(timestamp) {
			if (!timestamp) return '-'
			return new Date(timestamp * 1000).toLocaleDateString('de-DE')
		},
		formatPiiWarningTypes(warnings) {
			const labels = {
				email: t('learning', 'E-Mail-Adressen'),
				phone: t('learning', 'Telefonnummern'),
				name: t('learning', 'Klarnamen'),
			}
			const uniqueTypes = [...new Set((warnings || []).map((warning) => labels[warning.type] || warning.type))]
			return uniqueTypes.join(', ')
		},

		autoDismissSuccess() {
			setTimeout(() => {
				this.success = null
			}, 5000)
		},
	},
}
</script>

<style scoped>
.student-knowledge-contribute {
	padding: 16px 0;
	position: relative;
}

.swarm-consent-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.swarm-consent-dialog {
	background: var(--color-main-background, #fff);
	border-radius: 12px;
	padding: 24px 28px;
	max-width: 520px;
	width: 90%;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.swarm-consent-dialog h3 {
	margin: 0 0 16px 0;
	font-size: 18px;
}

.swarm-consent-body p {
	margin: 0 0 12px 0;
	font-size: 14px;
	line-height: 1.5;
}

.swarm-consent-list {
	margin: 0 0 16px 0;
	padding-left: 20px;
	font-size: 14px;
	line-height: 1.6;
}

.swarm-consent-list li {
	margin-bottom: 8px;
}

.swarm-consent-btn {
	width: 100%;
	margin-top: 8px;
}

.contribute-form {
	margin-bottom: 24px;
}

.contribute-form h4 {
	margin: 0 0 8px 0;
}

.contribute-hint {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 14px;
	margin: 0 0 12px 0;
}

.contribute-title-input {
	width: 100%;
	max-width: 500px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
	margin-bottom: 8px;
}

.contribute-textarea {
	width: 100%;
	min-height: 120px;
	padding: 10px 12px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
	resize: vertical;
}

.contribute-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-top: 10px;
}

.contribute-counter {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
}

.success-message {
	margin-top: 10px;
	padding: 8px 12px;
	background: #e8f5e9;
	color: #2e7d32;
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
}

.warning-message {
	margin-top: 10px;
	padding: 8px 12px;
	background: color-mix(in srgb, var(--color-warning, #f0b429) 18%, white);
	color: var(--color-main-text, #222);
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
}

.error-message {
	margin-top: 10px;
	padding: 8px 12px;
	background: #ffebee;
	color: #c62828;
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
}

.contributions-list-section {
	margin-top: 20px;
}

.contributions-list-section h4 {
	margin: 0 0 12px 0;
}

.empty-state {
	padding: 24px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast, #767676);
}

.contributions-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.contribution-item {
	padding: 12px 16px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background, #fff);
}

.contribution-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
}

.contribution-title {
	font-weight: 600;
	font-size: 14px;
	word-break: break-word;
}

.status-badge {
	flex-shrink: 0;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
}

.status-pending {
	background: #fff3e0;
	color: #e65100;
}

.status-approved {
	background: #e8f5e9;
	color: #2e7d32;
}

.status-rejected {
	background: #ffebee;
	color: #c62828;
}

.contribution-meta {
	margin-top: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #767676);
}

.material-btn {
	padding: 8px 16px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background, #fff);
	cursor: pointer;
	font-size: 14px;
	min-height: 44px;
}

.material-btn.primary {
	background: var(--color-primary-element, #00679e);
	color: var(--color-primary-element-text, #fff);
	border-color: var(--color-primary-element, #00679e);
}

.material-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 24px;
	gap: 8px;
}

@media (max-width: 768px) {
	.contribute-title-input {
		max-width: none;
	}

	.contribute-actions {
		flex-direction: column;
		align-items: flex-start;
	}
}
</style>
