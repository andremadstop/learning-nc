<template>
	<div v-if="isInstructor" class="course-knowledge-import">
		<!-- Import Form -->
		<div class="import-form">
			<h4>{{ t('learning', 'Wissen importieren') }}</h4>
			<p class="import-hint">
				{{ t('learning', 'Diese Wissensquellen stehen VirtuProf als Kurskontext zur Verfügung. Schwarm-Beiträge von Studierenden werden getrennt moderiert und erscheinen hier nicht.') }}
			</p>

			<div class="import-mode-toggle">
				<button
					class="material-btn"
					:class="{ primary: importMode === 'text' }"
					@click="importMode = 'text'">
					{{ t('learning', 'Text einfügen') }}
				</button>
				<button
					class="material-btn"
					:class="{ primary: importMode === 'file' }"
					@click="importMode = 'file'">
					{{ t('learning', 'Datei hochladen') }}
				</button>
			</div>

			<!-- Text paste mode -->
			<div v-if="importMode === 'text'" class="import-text-form">
				<input
					v-model="textTitle"
					type="text"
					class="import-title-input"
					:placeholder="t('learning', 'z.B. Kapitel 3 - Netzwerktopologien')" />
				<textarea
					v-model="textContent"
					class="import-textarea"
					rows="12"
					:placeholder="t('learning', 'Markdown oder Text hier einfügen...')" />
				<button
					class="material-btn primary"
					:disabled="!textTitle.trim() || !textContent.trim() || importing"
					@click="importText">
					{{ importing ? t('learning', 'Importiere...') : t('learning', 'Importieren') }}
				</button>
			</div>

			<!-- File upload mode -->
			<div v-if="importMode === 'file'" class="import-file-form">
				<input
					ref="fileInput"
					type="file"
					accept=".md,.txt"
					class="import-file-input"
					@change="onFileSelected" />
				<p v-if="selectedFile" class="selected-file-name">{{ selectedFile.name }}</p>
				<button
					class="material-btn primary"
					:disabled="!selectedFile || importing"
					@click="importFile">
					{{ importing ? t('learning', 'Importiere...') : t('learning', 'Datei importieren') }}
				</button>
			</div>

			<NcNoteCard v-if="success" type="success" class="import-message">
				{{ success }}
			</NcNoteCard>

			<NcNoteCard v-if="error" type="error" class="import-message">
				{{ error }}
			</NcNoteCard>
		</div>

		<!-- Imported Documents List -->
		<div class="import-list-section">
			<h4>{{ t('learning', 'Importierte Wissensquellen') }}</h4>

			<div v-if="loading" class="loading-container">
				<NcLoadingIcon :size="28" />
				<p>{{ t('learning', 'Lade...') }}</p>
			</div>

			<div v-else-if="imports.length === 0" class="empty-state">
				<p>{{ t('learning', 'Noch keine Wissensquellen importiert.') }}</p>
			</div>

			<div v-else class="material-table-wrapper">
				<table class="material-table">
					<thead>
						<tr>
							<th>{{ t('learning', 'Titel') }}</th>
							<th>{{ t('learning', 'Chunks') }}</th>
							<th>{{ t('learning', 'Tokens') }}</th>
							<th>{{ t('learning', 'Importiert am') }}</th>
							<th>{{ t('learning', 'Aktion') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in imports" :key="item.source_file">
							<td class="cell-title">{{ item.source_file }}</td>
							<td>{{ item.chunk_count }}</td>
							<td>{{ item.token_count }}</td>
							<td>{{ formatDate(item.created_at) }}</td>
							<td>
								<button
									class="material-btn small danger"
									:disabled="deleting[item.source_file]"
									@click="deleteImport(item.source_file)">
									{{ deleting[item.source_file] ? t('learning', 'Lösche...') : t('learning', 'Löschen') }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'CourseKnowledgeImport',
	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

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
			imports: [],
			importMode: 'text',
			textTitle: '',
			textContent: '',
			selectedFile: null,
			importing: false,
			loading: false,
			error: null,
			success: null,
			deleting: {},
		}
	},

	mounted() {
		if (this.isInstructor) {
			this.loadImports()
		}
	},

	methods: {
		t,

		async loadImports() {
			this.loading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge', { courseId: this.courseId })
				const response = await axios.get(url)
				this.imports = response.data || []
			} catch (e) {
				this.error = e.response?.data?.error || t('learning', 'Wissensquellen konnten nicht geladen werden.')
			} finally {
				this.loading = false
			}
		},

		async importText() {
			if (!this.textTitle.trim() || !this.textContent.trim()) return
			this.importing = true
			this.error = null
			this.success = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge/import-text', { courseId: this.courseId })
				const response = await axios.post(url, {
					title: this.textTitle.trim(),
					text: this.textContent,
				})
				const data = response.data
				this.success = t('learning', '{chunks} Chunks ({tokens} Tokens) importiert fuer "{title}"', {
					chunks: data.chunks,
					tokens: data.tokens,
					title: data.title,
				})
				this.textTitle = ''
				this.textContent = ''
				await this.loadImports()
				this.autoDismissSuccess()
			} catch (e) {
				this.error = e.response?.data?.error || t('learning', 'Import fehlgeschlagen')
			} finally {
				this.importing = false
			}
		},

		async importFile() {
			if (!this.selectedFile) return
			this.importing = true
			this.error = null
			this.success = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge/import-file', { courseId: this.courseId })
				const formData = new FormData()
				formData.append('file', this.selectedFile)
				const response = await axios.post(url, formData)
				const data = response.data
				this.success = t('learning', '{chunks} Chunks ({tokens} Tokens) importiert fuer "{title}"', {
					chunks: data.chunks,
					tokens: data.tokens,
					title: data.title,
				})
				this.selectedFile = null
				if (this.$refs.fileInput) {
					this.$refs.fileInput.value = ''
				}
				await this.loadImports()
				this.autoDismissSuccess()
			} catch (e) {
				this.error = e.response?.data?.error || t('learning', 'Import fehlgeschlagen')
			} finally {
				this.importing = false
			}
		},

		async deleteImport(title) {
			if (!confirm(t('learning', 'Wirklich löschen?'))) return
			this.error = null
			this.$set(this.deleting, title, true)
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge/{title}', {
					courseId: this.courseId,
					title,
				})
				await axios.delete(url)
				this.imports = this.imports.filter(i => i.source_file !== title)
			} catch (e) {
				this.error = e.response?.data?.error || t('learning', 'Löschen fehlgeschlagen')
			} finally {
				this.$set(this.deleting, title, false)
			}
		},

		onFileSelected(event) {
			const files = event.target.files
			this.selectedFile = files && files.length > 0 ? files[0] : null
			this.error = null
			this.success = null
		},

		formatDate(timestamp) {
			if (!timestamp) return '-'
			return new Date(timestamp * 1000).toLocaleDateString('de-DE')
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
.course-knowledge-import {
	padding: 16px 0;
}

.import-form {
	margin-bottom: 24px;
}

.import-form h4 {
	margin: 0 0 12px 0;
}

.import-hint {
	margin: 0 0 16px 0;
	color: var(--color-text-maxcontrast, #767676);
}

.import-mode-toggle {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.import-text-form,
.import-file-form {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.import-title-input {
	max-width: 500px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
}

.import-textarea {
	width: 100%;
	min-height: 200px;
	padding: 10px 12px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	font-family: monospace;
	font-size: 14px;
	resize: vertical;
}

.import-file-input {
	padding: 8px 0;
}

.selected-file-name {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast, #767676);
}

.import-message {
	margin-top: 10px;
}

.import-list-section {
	margin-top: 20px;
}

.import-list-section h4 {
	margin: 0 0 12px 0;
}

.empty-state {
	padding: 24px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast, #767676);
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

.cell-title {
	word-break: break-all;
	max-width: 300px;
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

.material-btn.danger {
	color: #c62828;
	border-color: #ef9a9a;
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
	.import-mode-toggle {
		flex-direction: column;
	}

	.import-title-input {
		max-width: none;
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
