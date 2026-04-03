<template>
	<div class="course-materials">
		<!-- Folder Setting Section (instructor only) -->
		<div v-if="isInstructor" class="material-folder-section">
			<h4>{{ t('learning', 'Materialordner') }}</h4>
			<div class="folder-input-row">
				<input
					v-model="folderInput"
					type="text"
					class="folder-input"
					:placeholder="t('learning', '/Kursmaterial')"
					:disabled="settingFolder" />
				<button
					class="primary material-btn"
					:disabled="!folderInput.trim() || settingFolder"
					@click="setFolder">
					{{ settingFolder ? t('learning', 'Verknüpfe...') : t('learning', 'Ordner verknüpfen') }}
				</button>
			</div>
			<p v-if="materialFolder" class="current-folder">
				{{ t('learning', 'Aktueller Ordner:') }} <strong>{{ materialFolder }}</strong>
			</p>
		</div>

		<!-- Error Message -->
		<div v-if="errorMessage" class="error-message">
			{{ errorMessage }}
		</div>

		<!-- Empty States -->
		<div v-if="!materialFolder && !loading" class="empty-state">
			<p>{{ t('learning', 'Kein Materialordner verknüpft. Bitte einen Nextcloud-Ordner auswählen.') }}</p>
		</div>

		<div v-else-if="materialFolder && documents.length === 0 && !loading" class="empty-state">
			<p>{{ t('learning', 'Keine Dokumente gefunden. Laden Sie PDF- oder Markdown-Dateien in den Ordner hoch und klicken Sie "Ordner scannen".') }}</p>
		</div>

		<!-- Loading -->
		<div v-if="loading" class="loading-container">
			<span class="icon-loading" />
			<p>{{ t('learning', 'Lade Materialien...') }}</p>
		</div>

		<!-- Action Buttons (instructor only) -->
		<div v-if="isInstructor && materialFolder" class="material-actions">
			<button
				class="material-btn"
				:disabled="scanning"
				@click="scanFolder">
				{{ scanning ? t('learning', 'Scanne...') : t('learning', 'Ordner scannen') }}
			</button>
			<button
				class="material-btn primary"
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
						<th>{{ t('learning', 'Status') }}</th>
						<th>{{ t('learning', 'Größe') }}</th>
						<th>{{ t('learning', 'Hochgeladen') }}</th>
						<th v-if="isInstructor">{{ t('learning', 'Aktion') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="doc in documents" :key="doc.id">
						<td class="cell-filename">{{ doc.file_name }}</td>
						<td>
							<span class="type-badge" :class="'type-' + doc.file_type">
								{{ doc.file_type === 'pdf' ? 'PDF' : 'Markdown' }}
							</span>
						</td>
						<td>
							<span
								class="status-badge"
								:class="'status-' + doc.status"
								:title="doc.status === 'error' ? doc.error_message : ''">
								{{ statusLabel(doc.status) }}
							</span>
						</td>
						<td>{{ formatFileSize(doc.file_size) }}</td>
						<td>{{ formatDate(doc.created_at) }}</td>
						<td v-if="isInstructor">
							<button
								v-if="doc.status === 'uploaded'"
								class="material-btn small"
								:disabled="extracting[doc.id]"
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
		}
	},

	computed: {
		hasExtractableDocuments() {
			return this.documents.some(doc => doc.status === 'uploaded')
		},
	},

	mounted() {
		this.loadData()
	},

	methods: {
		t,

		async loadData() {
			this.loading = true
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
			}
		},

		async loadDocuments() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/documents', { courseId: this.courseId })
				const response = await axios.get(url)
				this.documents = response.data || []
			} catch (e) {
				console.error('Failed to load documents', e)
			}
		},

		async setFolder() {
			if (!this.folderInput.trim()) return
			this.settingFolder = true
			this.errorMessage = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/material-folder', { courseId: this.courseId })
				await axios.post(url, { folder: this.folderInput.trim() })
				this.materialFolder = this.folderInput.trim()
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
