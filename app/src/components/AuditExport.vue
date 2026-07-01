<template>
	<div class="audit-export">
		<h2>{{ t('learning', 'Audit-Export') }}</h2>
		<p class="audit-export__hint">
			{{ t('learning', 'Lade ein signiertes, manipulationssicheres Ereignisprotokoll der Compliance-Kette herunter. Nur für Mitglieder der Auditor-Gruppe.') }}
		</p>

		<div class="audit-export__filters">
			<label class="audit-export__field">
				{{ t('learning', 'Von (Datum)') }}
				<input v-model="fromDate" type="date">
			</label>
			<label class="audit-export__field">
				{{ t('learning', 'Bis (Datum)') }}
				<input v-model="toDate" type="date">
			</label>
			<label class="audit-export__field">
				{{ t('learning', 'Kurs-ID (optional)') }}
				<input v-model="courseId" type="number" min="1" :placeholder="t('learning', 'alle')">
			</label>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div class="audit-export__actions">
			<NcButton type="primary" :disabled="loading" @click="download('events')">
				{{ t('learning', 'Ereignisse herunterladen (JSONL)') }}
			</NcButton>
			<NcButton :disabled="loading" @click="download('sig')">
				{{ t('learning', 'Signatur herunterladen (.sig)') }}
			</NcButton>
			<NcButton :disabled="loading" @click="openReport">
				{{ t('learning', 'Bericht öffnen (Drucken / PDF)') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'AuditExport',

	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			fromDate: '',
			toDate: '',
			courseId: '',
			loading: false,
			error: null,
		}
	},

	methods: {
		/**
		 * Convert a YYYY-MM-DD value to a unix timestamp (start or end of day).
		 *
		 * @param {string} dateStr the date input value
		 * @param {boolean} endOfDay whether to snap to 23:59:59 instead of 00:00:00
		 * @return {number|null} unix seconds, or null when unset/invalid
		 */
		toTs(dateStr, endOfDay) {
			if (!dateStr) {
				return null
			}
			const ms = Date.parse(dateStr + (endOfDay ? 'T23:59:59' : 'T00:00:00'))
			return Number.isNaN(ms) ? null : Math.floor(ms / 1000)
		},

		/**
		 * Build the query string from the current filter selection.
		 *
		 * @return {string} the leading-'?' query string, or '' when no filters are set
		 */
		buildQuery() {
			const params = new URLSearchParams()
			const from = this.toTs(this.fromDate, false)
			const to = this.toTs(this.toDate, true)
			if (from !== null) {
				params.set('from_date', String(from))
			}
			if (to !== null) {
				params.set('to_date', String(to))
			}
			if (this.courseId) {
				params.set('course_id', String(this.courseId))
			}
			const qs = params.toString()
			return qs ? ('?' + qs) : ''
		},

		/**
		 * Absolute app URL for an export endpoint (webroot + index.php aware).
		 *
		 * @param {string} kind one of 'events' | 'sig' | 'report'
		 * @return {string} the download URL including the current filter query
		 */
		endpointUrl(kind) {
			return generateUrl('/apps/learning/audit/export/' + kind) + this.buildQuery()
		},

		/**
		 * Trigger a file download by navigating a transient anchor (JSONL / .sig).
		 *
		 * @param {string} kind one of 'events' | 'sig'
		 */
		download(kind) {
			this.error = null
			this.loading = true
			try {
				const a = document.createElement('a')
				a.href = this.endpointUrl(kind)
				a.setAttribute('download', '')
				document.body.appendChild(a)
				a.click()
				document.body.removeChild(a)
			} catch (e) {
				this.error = t('learning', 'Download fehlgeschlagen. Auditor-Berechtigung erforderlich.')
			} finally {
				this.loading = false
			}
		},

		/** Open the printable HTML report in a new tab for window.print(). */
		openReport() {
			this.error = null
			window.open(this.endpointUrl('report'), '_blank', 'noopener')
		},
	},
}
</script>

<style scoped>
.audit-export {
	max-width: 720px;
	margin: 24px auto;
	padding: 0 16px;
}

.audit-export__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.audit-export__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 16px 0;
}

.audit-export__field {
	display: flex;
	flex-direction: column;
	font-weight: 600;
}

.audit-export__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-top: 16px;
}
</style>
