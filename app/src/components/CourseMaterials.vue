<template>
	<div class="course-materials">
		<!-- Folder Setting Section (course owner only: the folder lives in the owner's home) -->
		<!-- Hidden while the page could not be loaded: an empty field would invite the owner to
			link a folder over the one that is linked (GitHub #35). -->
		<div v-if="managesFiles && !loadError" class="material-folder-section">
			<h4>{{ t('learning', 'Materialordner') }}</h4>
			<div class="folder-input-row">
				<input
					v-model="folderInput"
					type="text"
					class="folder-input"
					:placeholder="t('learning', '/Kursmaterial')"
					:aria-label="t('learning', 'Materialordner')"
					:disabled="settingFolder" />
				<button
					type="button"
					class="material-btn pick-folder-btn"
					:disabled="settingFolder"
					@click="pickFolder">
					{{ t('learning', 'Ordner auswählen…') }}
				</button>
				<button
					type="button"
					class="primary material-btn"
					:disabled="!folderInput.trim() || settingFolder"
					@click="setFolder">
					{{ settingFolder ? t('learning', 'Verknüpfe...') : t('learning', 'Ordner verknüpfen') }}
				</button>
			</div>
			<p v-if="materialFolder" class="current-folder">
				{{ t('learning', 'Aktueller Ordner:') }} <strong>{{ materialFolder }}</strong>
			</p>
			<p class="folder-visibility-hint">
				{{ t('learning', 'Kursmitglieder können jede PDF- und Markdown-Datei in diesem Ordner öffnen.') }}
			</p>
		</div>

		<!-- Error Message -->
		<div v-if="errorMessage" class="error-message">
			{{ errorMessage }}
		</div>

		<!-- A failed load is no empty course (GitHub #35): say so and offer a retry. -->
		<div v-if="loadError && !loading" class="error-message load-error" role="alert">
			<p>{{ t('learning', 'Die Lernmaterialien konnten nicht geladen werden.') }}</p>
			<button type="button" class="material-btn retry-btn" @click="loadData">
				{{ t('learning', 'Erneut versuchen') }}
			</button>
		</div>

		<!-- Empty States -->
		<div v-else-if="!materialFolder && !loading" class="empty-state">
			<p>{{ t('learning', 'Kein Materialordner verknüpft. Bitte einen Nextcloud-Ordner auswählen.') }}</p>
		</div>

		<div v-else-if="managesFiles && materialFolder && documents.length === 0 && !loading" class="empty-state">
			<p>{{ t('learning', 'Keine Dokumente gefunden. Laden Sie PDF- oder Markdown-Dateien in den Ordner hoch und klicken Sie "Ordner scannen".') }}</p>
		</div>

		<!-- Learners can neither upload nor scan: no instruction meant for the course owner. -->
		<div v-else-if="materialFolder && documents.length === 0 && !loading" class="empty-state learner-empty-state">
			<p>{{ t('learning', 'Für diesen Kurs wurden noch keine Lernmaterialien freigegeben.') }}</p>
		</div>

		<!-- Loading -->
		<div v-if="loading" class="loading-container">
			<span class="icon-loading" />
			<p>{{ t('learning', 'Lade Materialien...') }}</p>
		</div>

		<!-- Text extraction only feeds the AI assistant (GitHub #35): say so, and say it up front
			when this server cannot read PDFs at all. -->
		<!-- Neither this nor the actions below while the load failed: managesFiles is still true
			when the document list (which carries canManageFiles) could not be read. -->
		<template v-if="canExtract && materialFolder && !loadError">
			<p class="extract-explanation">
				{{ t('learning', 'Extrahieren liest den Text der Dateien, damit VirtuProf (KI-Assistent) das Material als Kurskontext nutzen kann. Zum Lesen brauchen Lernende das nicht.') }}
			</p>
			<p v-if="pdfTextExtraction === false" class="pdf-unavailable-note" role="status">
				{{ t('learning', 'PDF-Textextraktion ist auf diesem Server nicht verfügbar (pdftotext fehlt). Lernende können die Dateien trotzdem öffnen.') }}
			</p>
		</template>

		<!-- Action Buttons (course owner only) -->
		<div v-if="managesFiles && materialFolder && !loadError" class="material-actions">
			<button
				type="button"
				class="material-btn scan-btn"
				:disabled="scanning"
				@click="scanFolder">
				{{ scanning ? t('learning', 'Scanne...') : t('learning', 'Ordner scannen') }}
			</button>
			<button
				v-if="canExtract"
				type="button"
				class="material-btn primary extract-all-btn"
				:disabled="extractingAll || !hasExtractableDocuments"
				@click="extractAll">
				{{ extractingAll ? t('learning', 'Extrahiere alle...') : t('learning', 'Alle extrahieren') }}
			</button>
		</div>

		<!-- Document List Table -->
		<div v-if="documents.length > 0" class="material-table-wrapper">
			<table class="material-table">
				<thead>
					<tr>
						<th>{{ t('learning', 'Dateiname') }}</th>
						<th>{{ t('learning', 'Typ') }}</th>
						<th v-if="showExtractionStatus">
							{{ t('learning', 'Status') }}
						</th>
						<th>{{ t('learning', 'Größe') }}</th>
						<th>{{ t('learning', 'Hochgeladen') }}</th>
						<th v-if="canExtract">
							{{ t('learning', 'Aktion') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="doc in documents" :key="doc.id">
						<td class="cell-filename">
							<a
								v-if="doc.downloadable"
								class="material-file-link"
								:href="downloadUrl(doc)"
								target="_blank"
								rel="noopener noreferrer">{{ doc.file_name }}</a>
							<span
								v-else
								class="material-file-unavailable"
								:title="t('learning', 'Diese Datei liegt nicht mehr im Materialordner.')">{{ doc.file_name }}</span>
						</td>
						<td>
							<span class="type-badge" :class="'type-' + doc.file_type">
								{{ doc.file_type === 'pdf' ? 'PDF' : 'Markdown' }}
							</span>
						</td>
						<td v-if="showExtractionStatus">
							<span
								class="status-badge"
								:class="'status-' + doc.status"
								:title="doc.status === 'error' ? doc.error_message : ''">
								{{ statusLabel(doc.status) }}
							</span>
						</td>
						<td>{{ formatFileSize(doc.file_size) }}</td>
						<td>{{ formatDate(doc.created_at) }}</td>
						<td v-if="canExtract">
							<button
								v-if="doc.status === 'uploaded'"
								type="button"
								class="material-btn small extract-btn"
								:disabled="extracting[doc.id] || !isExtractable(doc)"
								:title="isExtractable(doc) ? '' : t('learning', 'Ohne pdftotext nicht möglich')"
								@click="extractDocument(doc)">
								{{ extracting[doc.id] ? t('learning', 'Extrahiere...') : t('learning', 'Extrahieren') }}
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { parseDocumentsResponse } from '../utils/courseMaterials.js'

export default {
	name: 'CourseMaterials',

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		isInstructor: {
			type: Boolean,
			default: false,
		},
		// GitHub #35: the admin's AI switch. Text extraction only feeds the AI assistant, so
		// without it there is nothing to extract for.
		aiEnabled: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			documents: [],
			materialFolder: null,
			folderInput: '',
			loading: false,
			scanning: false,
			extractingAll: false,
			settingFolder: false,
			extracting: {},
			errorMessage: null,
			// GitHub #35: the folder or the document list could not be read
			loadError: false,
			// null until the server said so (older server, failed read)
			canManageFiles: null,
			pdfTextExtraction: null,
		}
	},

	computed: {
		// Folder, scan and extraction run in the course owner's home; a co-instructor gets a 403
		// there. The server does not know about the student view, so the instructor role of this
		// view (false while the owner previews the course as a learner) always has a say; without
		// an answer from the server it alone decides, as before.
		managesFiles() {
			return this.isInstructor && this.canManageFiles !== false
		},
		canExtract() {
			return this.managesFiles && this.aiEnabled
		},
		// The status only describes the text extraction for the AI assistant.
		showExtractionStatus() {
			return this.isInstructor && this.aiEnabled
		},
		hasExtractableDocuments() {
			return this.documents.some(doc => doc.status === 'uploaded' && this.isExtractable(doc))
		},
	},

	mounted() {
		this.loadData()
	},

	methods: {
		t,

		async loadData() {
			this.loading = true
			this.loadError = false
			try {
				await Promise.all([
					this.loadFolder(),
					this.loadDocuments(),
				])
			} finally {
				this.loading = false
			}
		},

		async loadFolder() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/material-folder', { courseId: this.courseId })
				const response = await axios.get(url)
				this.materialFolder = response.data.folder || null
				if (this.materialFolder) {
					this.folderInput = this.materialFolder
				}
			} catch (e) {
				console.error('Failed to load material folder', e)
				// a retry that fails keeps no folder from an earlier read
				this.materialFolder = null
				this.loadError = true
			}
		},

		async loadDocuments() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/documents', { courseId: this.courseId })
				const response = await axios.get(url)
				const parsed = parseDocumentsResponse(response.data)
				this.documents = parsed.documents
				this.canManageFiles = parsed.canManageFiles
				this.pdfTextExtraction = parsed.pdfTextExtraction
			} catch (e) {
				console.error('Failed to load documents', e)
				// no rows (with extract buttons) from an earlier read under the load error; the
				// last known canManageFiles stays, null would hand a co-instructor the controls
				this.documents = []
				this.loadError = true
			}
		},

		downloadUrl(doc) {
			return generateUrl('/apps/learning/api/courses/{courseId}/documents/{documentId}/download', {
				courseId: this.courseId,
				documentId: doc.id,
			})
		},

		isExtractable(doc) {
			return doc.file_type !== 'pdf' || this.pdfTextExtraction !== false
		},

		// GitHub #35: choose the folder in the Nextcloud file picker instead of typing a path.
		// The text field stays for manual entry.
		async pickFolder() {
			let chosen = null
			try {
				const picker = getFilePickerBuilder(t('learning', 'Materialordner auswählen'))
					.setMultiSelect(false)
					.allowDirectories(true)
					.setMimeTypeFilter(['httpd/unix-directory'])
					.setButtonFactory((selected, currentPath) => {
						const target = selected[0]?.path || currentPath
						return [{
							label: t('learning', 'Diesen Ordner verknüpfen'),
							variant: 'primary',
							// the whole home folder is no material folder
							disabled: !target || target === '/',
							callback: (nodes) => {
								chosen = nodes[0]?.path || currentPath
							},
						}]
					})
					.build()
				await picker.pickNodes()
			} catch (e) {
				// closed without choosing
				return
			}
			if (!chosen || chosen === '/') {
				return
			}
			// the picker spells '/Kurs'; a folder typed as 'Kurs' is the same one
			const trimSlashes = (path) => String(path || '').replace(/^\/+|\/+$/g, '')
			if (trimSlashes(chosen) === trimSlashes(this.materialFolder)) {
				return
			}
			this.folderInput = chosen
			await this.setFolder()
		},

		async setFolder() {
			if (!this.folderInput.trim()) return
			this.settingFolder = true
			this.errorMessage = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/material-folder', { courseId: this.courseId })
				const response = await axios.post(url, { folder: this.folderInput.trim() })
				// the server stores one spelling of the path (GitHub #35)
				this.materialFolder = response.data?.folder || this.folderInput.trim()
				this.folderInput = this.materialFolder
				await this.scanFolder()
			} catch (e) {
				this.errorMessage = e.response?.data?.error || t('learning', 'Ordner konnte nicht verknüpft werden. Prüfen Sie ob der Pfad existiert.')
			} finally {
				this.settingFolder = false
			}
		},

		async scanFolder() {
			this.scanning = true
			this.errorMessage = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/documents/scan', { courseId: this.courseId })
				const response = await axios.post(url)
				this.documents = response.data || []
			} catch (e) {
				this.errorMessage = e.response?.data?.error || t('learning', 'Ordner konnte nicht gescannt werden.')
			} finally {
				this.scanning = false
			}
		},

		async extractDocument(doc) {
			this.extracting[doc.id] = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/documents/{documentId}/extract', {
					courseId: this.courseId,
					documentId: doc.id,
				})
				const response = await axios.post(url)
				const index = this.documents.findIndex(d => d.id === doc.id)
				if (index !== -1) {
					this.documents[index] = response.data
				}
			} catch (e) {
				console.error('Failed to extract document', e)
				const index = this.documents.findIndex(d => d.id === doc.id)
				if (index !== -1) {
					this.documents[index].status = 'error'
				}
			} finally {
				this.extracting[doc.id] = false
			}
		},

		async extractAll() {
			this.extractingAll = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/documents/extract-all', { courseId: this.courseId })
				const response = await axios.post(url)
				this.documents = response.data || []
			} catch (e) {
				console.error('Failed to extract all documents', e)
			} finally {
				this.extractingAll = false
			}
		},

		statusLabel(status) {
			const labels = {
				uploaded: t('learning', 'Hochgeladen'),
				extracted: t('learning', 'Extrahiert'),
				error: t('learning', 'Fehler'),
			}
			return labels[status] || status
		},

		formatFileSize(bytes) {
			if (!bytes || bytes === 0) return '0 B'
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
		},

		formatDate(timestamp) {
			if (!timestamp) return '-'
			return new Date(timestamp * 1000).toLocaleDateString('de-DE')
		},
	},
}
</script>

<style scoped>
.course-materials {
	padding: 16px 0;
}

.material-folder-section {
	margin-bottom: 20px;
}

.material-folder-section h4 {
	margin: 0 0 8px 0;
}

.folder-input-row {
	display: flex;
	gap: 8px;
	align-items: center;
}

.folder-input {
	flex: 1;
	max-width: 400px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
}

.folder-visibility-hint {
	margin-top: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #767676);
}

.current-folder {
	margin-top: 8px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #767676);
}

.error-message {
	padding: 10px 14px;
	margin-bottom: 12px;
	background: #ffebee;
	color: #c62828;
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
}

.load-error p {
	margin: 0 0 8px;
}

.empty-state {
	padding: 32px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast, #767676);
}

.material-actions {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
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

.material-btn.small {
	padding: 4px 12px;
	font-size: 13px;
	min-height: 36px;
}

.material-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.material-table-wrapper {
	overflow-x: auto;
}

.material-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.material-table th,
.material-table td {
	padding: 10px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border, #ededed);
}

.material-table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
}

.cell-filename {
	word-break: break-all;
	max-width: 300px;
}

.material-file-link {
	color: var(--color-primary-element, #00679e);
	text-decoration: underline;
}

.material-file-unavailable {
	color: var(--color-text-maxcontrast, #767676);
	cursor: help;
}

.extract-explanation,
.pdf-unavailable-note {
	margin: 0 0 12px 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #767676);
}

.pdf-unavailable-note {
	padding: 8px 12px;
	border-left: 4px solid var(--color-warning, #e9a700);
	background: var(--color-background-hover, #f5f5f5);
	color: var(--color-main-text, #222);
}

.type-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
}

.type-pdf {
	background: #fce4ec;
	color: #c62828;
}

.type-markdown {
	background: #e3f2fd;
	color: #1565c0;
}

.status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
}

.status-uploaded {
	background: #fff3e0;
	color: #e65100;
}

.status-extracted {
	background: #e8f5e9;
	color: #2e7d32;
}

.status-error {
	background: #ffebee;
	color: #c62828;
	cursor: help;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 32px;
	gap: 8px;
}

@media (max-width: 768px) {
	.folder-input-row {
		flex-direction: column;
		align-items: stretch;
	}

	.folder-input {
		max-width: none;
	}

	.material-actions {
		flex-direction: column;
	}

	.material-table {
		font-size: 13px;
	}

	.material-table th,
	.material-table td {
		padding: 8px 6px;
	}
}
</style>
