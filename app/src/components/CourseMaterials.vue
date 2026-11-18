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
		<div v-else-if="!materialFolder && !loading && !hideEmpty" class="empty-state">
			<p>{{ t('learning', 'Kein Materialordner verknüpft. Bitte einen Nextcloud-Ordner auswählen.') }}</p>
		</div>

		<div v-else-if="managesFiles && materialFolder && documents.length === 0 && !loading" class="empty-state">
			<p>{{ t('learning', 'Keine Dokumente gefunden. Laden Sie PDF- oder Markdown-Dateien in den Ordner hoch und klicken Sie "Ordner scannen".') }}</p>
		</div>

		<!-- Learners can neither upload nor scan: no instruction meant for the course owner. -->
		<div v-else-if="materialFolder && documents.length === 0 && !loading && !hideEmpty" class="empty-state learner-empty-state">
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

		<!-- GitHub #89: ticked documents hold the exam back only with the switch below the table.
			Without it the marks only track reading, which an instructor would not guess. -->
		<div
			v-if="requiredWithoutGate"
			class="required-gate-off-note"
			role="note"
			data-test="required-gate-off">
			<p>{{ t('learning', 'Die Markierungen in der Spalte „Pflicht“ sperren die Prüfung gerade nicht: Der Schalter „Prüfung erst nach Abschluss aller Pflichtinhalte“ ist aus. Lernende bestätigen die Dokumente, können die Prüfung aber auch ohne Bestätigung ablegen.') }}</p>
			<button
				type="button"
				class="material-btn small"
				data-test="required-gate-off-link"
				@click="showGateSwitch">
				{{ t('learning', 'Zum Schalter') }}
			</button>
		</div>

		<!-- Document List Table -->
		<div v-if="visibleDocuments.length > 0" class="material-table-wrapper">
			<table class="material-table">
				<thead>
					<tr>
						<!-- GitHub #75: the "Required" column; ticked = learners confirm it as read. -->
						<th v-if="showRequiredColumn" class="cell-required" scope="col">
							<label class="required-head">
								<input
									ref="requiredHeader"
									type="checkbox"
									data-test="required-all"
									:checked="requiredHeader === 'all'"
									:disabled="!hasSelectableDocuments"
									:aria-disabled="requiredBusy ? 'true' : null"
									:aria-label="t('learning', 'Pflicht: alle Dateien')"
									@change="setAllRequired($event.target.checked, $event.target)">
								<span>{{ t('learning', 'Pflicht') }}</span>
							</label>
						</th>
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
					<tr v-for="doc in visibleDocuments" :key="doc.id">
						<td v-if="showRequiredColumn" class="cell-required">
							<input
								type="checkbox"
								:data-test="'required-doc-' + doc.id"
								:checked="isRequired(doc)"
								:disabled="!isRequired(doc) && doc.downloadable !== true"
								:aria-disabled="requiredBusy ? 'true' : null"
								:aria-label="t('learning', '„{name}“ ist Pflicht', { name: doc.file_name }, undefined, RAW)"
								@change="setRequired([doc], $event.target.checked, $event.target)">
							<button
								v-if="isRequired(doc)"
								type="button"
								class="material-btn small reconfirm-btn"
								:data-test="'reconfirm-doc-' + doc.id"
								:aria-disabled="requiredBusy ? 'true' : null"
								:aria-label="t('learning', '„{name}“: alle erneut bestätigen lassen', { name: doc.file_name }, undefined, RAW)"
								:title="t('learning', 'Bisherige Bestätigungen dieser Datei zählen danach nicht mehr.')"
								@click="askToConfirmAgain(doc)">
								{{ t('learning', 'Alle erneut bestätigen lassen') }}
							</button>
						</td>
						<td class="cell-filename">
							<a
								v-if="doc.downloadable"
								class="material-file-link"
								:href="downloadUrl(doc)"
								target="_blank"
								rel="noopener noreferrer">{{ doc.file_name }}</a>
							<span
								v-else
								class="material-file-unavailable">{{ doc.file_name }}</span>
							<!-- GitHub #75: optional files in a course with required content -->
							<span v-if="optionalHint" class="material-optional-hint">{{ t('learning', 'Optional') }}</span>
							<!-- GitHub #90: an entry whose file left the folder can be taken out of the list -->
							<button
								v-if="isInstructor && isStale(doc)"
								type="button"
								class="material-remove-btn"
								:data-test="'remove-doc-' + doc.id"
								:disabled="removing[doc.id] === true"
								:aria-label="t('learning', '„{name}“ aus der Liste entfernen', { name: doc.file_name }, undefined, RAW)"
								:title="t('learning', 'Aus der Liste entfernen')"
								@click="removeEntry(doc)">
								<span aria-hidden="true">×</span>
							</button>
							<!-- GitHub #90: why it cannot be opened, as text (not only a tooltip) -->
							<span
								v-if="!doc.downloadable"
								class="material-unavailable-reason"
								:data-test="'unavailable-doc-' + doc.id">{{ unavailableText(doc) }}</span>
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
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatLocalDate } from '../utils/relativeTime.js'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { isStaleDocument, parseDocumentsResponse } from '../utils/courseMaterials.js'
import { registryRowForDocument, requiredDocumentIds, requiredHeaderState } from '../utils/requiredContent.js'

/** File names in texts: shown as text (interpolation, attributes, window.confirm), never as HTML. */
const RAW = { escape: false, sanitize: false }

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
		// GitHub #75 (learners): documents shown in the required list above are left out here,
		// the rest carry an "Optional" hint, and an empty list says nothing.
		excludeDocumentIds: {
			type: Array,
			default: () => [],
		},
		optionalHint: {
			type: Boolean,
			default: false,
		},
		hideEmpty: {
			type: Boolean,
			default: false,
		},
		// GitHub #89: "Exam only after all required content is completed" is off in this course
		examGateOff: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['required-changed', 'error'],

	data() {
		return {
			RAW,
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
			// GitHub #75: the required-content registry (instructors), for the "Required" column
			registry: [],
			registryLoaded: false,
			requiredBusy: false,
			// GitHub #90: entries being taken out of the list
			removing: {},
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
		// GitHub #75: owner and co-instructors (both may change the registry), not in the student view.
		showRequiredColumn() {
			return this.isInstructor && this.registryLoaded
		},
		requiredIds() {
			return requiredDocumentIds(this.registry)
		},
		// GitHub #89: documents are ticked "Required", but the exam is not held back by them
		requiredWithoutGate() {
			return this.showRequiredColumn && this.examGateOff && this.requiredIds.size > 0
		},
		requiredHeader() {
			return requiredHeaderState(this.documents, this.requiredIds)
		},
		hasSelectableDocuments() {
			return this.documents.some((doc) => doc.downloadable === true)
		},
		visibleDocuments() {
			// GitHub #90: the server sends instructors the entries whose file left the folder; in the
			// student view they are left out, as for learners.
			const shown = this.isInstructor ? this.documents : this.documents.filter((doc) => !isStaleDocument(doc))
			if (!this.excludeDocumentIds.length) {
				return shown
			}
			const excluded = new Set(this.excludeDocumentIds.map(Number))
			return shown.filter((doc) => !excluded.has(Number(doc.id)))
		},
		hasExtractableDocuments() {
			return this.documents.some(doc => doc.status === 'uploaded' && this.isExtractable(doc))
		},
	},

	watch: {
		// A native checkbox shows "some ticked" only through the property.
		requiredHeader: {
			flush: 'post',
			handler() {
				this.syncHeaderCheckbox()
			},
		},
		isInstructor(now) {
			if (now) {
				this.loadRegistry()
			}
		},
	},

	mounted() {
		this.loadData()
	},

	updated() {
		this.syncHeaderCheckbox()
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
					this.isInstructor ? this.loadRegistry() : Promise.resolve(),
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

		async loadRegistry() {
			try {
				const response = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/videos', { courseId: this.courseId }))
				this.registry = Array.isArray(response.data) ? response.data : []
				this.registryLoaded = true
			} catch (e) {
				// no column rather than a column that shows every file as optional
				this.registryLoaded = false
			}
		},

		syncHeaderCheckbox() {
			const box = this.$refs.requiredHeader
			if (box) {
				box.indeterminate = this.requiredHeader === 'some'
			}
		},

		isStale(doc) {
			return isStaleDocument(doc)
		},

		/** GitHub #90: why an entry cannot be opened, for the viewer. */
		unavailableText(doc) {
			if (!this.isInstructor) {
				return t('learning', 'Diese Datei kann gerade nicht geöffnet werden. Bitte wende dich an deine Lehrkraft.')
			}
			switch (doc.unavailable_reason) {
			case 'missing':
				return t('learning', 'Die Datei ist nicht mehr im Materialordner. „Ordner scannen“ nimmt den Eintrag aus der Liste.')
			case 'outside_folder':
				return t('learning', 'Die Datei gehört zu einem früher verknüpften Ordner. „Ordner scannen“ nimmt den Eintrag aus der Liste.')
			case 'folder_missing':
				return t('learning', 'Der Materialordner ist nicht erreichbar. Lernende können die Datei nicht öffnen.')
			case 'no_download':
				return t('learning', 'Die Datei ist nur zum Ansehen ohne Herunterladen freigegeben oder nicht lesbar. Lernende können sie deshalb nicht öffnen.')
			default:
				return t('learning', 'Diese Datei liegt nicht mehr im Materialordner.')
			}
		},

		/**
		 * GitHub #90: take an entry whose file left the material folder out of the list. A required
		 * one stops holding the exam back, so that is asked first; its confirmations are kept.
		 */
		async removeEntry(doc) {
			if (this.removing[doc.id]) {
				return
			}
			const required = this.isRequired(doc)
			if (required && !window.confirm(t('learning', '„{name}“ ist Pflicht. Ohne den Eintrag wartet die Prüfung nicht mehr auf die Datei. Die Bestätigungen bleiben erhalten und zählen wieder, wenn die Datei zurück in den Ordner kommt. Fortfahren?', { name: doc.file_name }, undefined, RAW))) {
				return
			}
			this.removing = { ...this.removing, [doc.id]: true }
			this.$emit('error', '')
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/documents/{documentId}', { courseId: this.courseId, documentId: doc.id })
				const response = await axios.delete(url)
				this.documents = Array.isArray(response.data) ? response.data : this.documents.filter((row) => row.id !== doc.id)
				if (required) {
					await this.reloadRegistryAfterListChange()
				}
			} catch (e) {
				this.$emit('error', e.response?.status === 409
					? t('learning', 'Die Datei liegt noch im Materialordner. Entferne sie dort, dann verschwindet sie beim nächsten Scan aus der Liste.')
					: t('learning', 'Der Eintrag konnte nicht entfernt werden.'))
			} finally {
				this.removing = { ...this.removing, [doc.id]: false }
			}
		},

		/** GitHub #90: entries left or came back, and with them required rows; the settings below follow. */
		async reloadRegistryAfterListChange() {
			if (!this.isInstructor) {
				return
			}
			await this.loadRegistry()
			if (this.registryLoaded) {
				this.$emit('required-changed', this.registry)
			}
		},

		/** GitHub #89: bring the exam switch (RequiredContentSettings, below the table) into view. */
		showGateSwitch() {
			const input = document.getElementById('learning-required-content-gate')
			if (!input) {
				return
			}
			input.scrollIntoView?.({ block: 'center' })
			input.focus({ preventScroll: true })
		},

		isRequired(doc) {
			return this.requiredIds.has(Number(doc.id))
		},

		async setAllRequired(required, box = null) {
			const docs = required
				? this.documents.filter((doc) => doc.downloadable === true)
				: this.documents.filter((doc) => this.isRequired(doc))
			await this.setRequired(docs, required, box)
		},

		/**
		 * GitHub #75 (review): making a required file optional takes its read confirmations out
		 * of the count until it is ticked again; they are kept and count again then, unless the
		 * file has changed (GitHub #75). Still asked first, so a misclick is noticed. Cancelled: the box
		 * shows the unchanged state again.
		 */
		confirmMakeOptional(docs) {
			const message = docs.length === 1
				? t('learning', '„{name}“ wird optional. Die bisherigen Bestätigungen bleiben erhalten und zählen wieder, wenn die Datei erneut Pflicht wird und sich bis dahin nicht geändert hat. Fortfahren?', { name: docs[0].file_name }, undefined, RAW)
				: n('learning', '%n Datei wird optional. Die bisherigen Bestätigungen bleiben erhalten und zählen wieder, wenn sie erneut Pflicht wird und sich bis dahin nicht geändert hat. Fortfahren?', '%n Dateien werden optional. Die bisherigen Bestätigungen bleiben erhalten und zählen wieder, wenn sie erneut Pflicht werden und sich bis dahin nicht geändert haben. Fortfahren?', docs.length)
			return window.confirm(message)
		},

		async setRequired(docs, required, box = null) {
			// GitHub #75 (review): the boxes stay focusable while a save runs (aria-disabled, not
			// disabled: a disabled control drops the keyboard focus to the page). A click meanwhile
			// changes nothing, so its box shows the stored state again.
			if (this.requiredBusy) {
				this.resetBox(box, !required)
				return
			}
			const documentIds = docs.map((doc) => Number(doc.id))
			if (documentIds.length === 0) {
				return
			}
			if (!required && !this.confirmMakeOptional(docs)) {
				this.resetBox(box, true)
				return
			}
			this.requiredBusy = true
			this.$emit('error', '') // a new attempt voids the last message, so a repeated one is announced again
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/required-documents', { courseId: this.courseId })
				const response = await axios.put(url, { documentIds, required })
				this.registry = Array.isArray(response.data) ? response.data : this.registry
				this.$emit('required-changed', this.registry)
			} catch (e) {
				// nothing was stored: the box must not show the click (:checked did not change, so
				// Vue does not patch it back)
				this.resetBox(box, !required)
				this.$emit('error', t('learning', 'Die Pflicht-Auswahl konnte nicht gespeichert werden.'))
			} finally {
				this.requiredBusy = false
			}
		},

		/** Show the stored state on a box again (after a cancel, a failed save, a click while saving). */
		resetBox(box, checked) {
			if (box) {
				box.checked = checked
			}
			this.$nextTick(() => this.syncHeaderCheckbox())
		},

		async askToConfirmAgain(doc) {
			const row = registryRowForDocument(this.registry, doc.id)
			if (!row || this.requiredBusy) {
				return
			}
			if (!window.confirm(t('learning', 'Alle Lernenden müssen „{name}“ erneut als gelesen bestätigen. Bisherige Bestätigungen bleiben im Audit-Protokoll. Fortfahren?', { name: doc.file_name }, undefined, RAW))) {
				return
			}
			this.requiredBusy = true
			this.$emit('error', '')
			try {
				const response = await axios.post(generateUrl('/apps/learning/api/course-videos/{id}/reconfirm', { id: row.id }))
				this.registry = this.registry.map((item) => (item.id === row.id ? { ...item, ...response.data } : item))
				this.$emit('required-changed', this.registry)
			} catch (e) {
				this.$emit('error', t('learning', 'Die erneute Bestätigung konnte nicht angefordert werden.'))
			} finally {
				this.requiredBusy = false
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
				// GitHub #90: the scan takes entries out of the list (and brings some back)
				await this.reloadRegistryAfterListChange()
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
			return formatLocalDate(timestamp)
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

.required-gate-off-note {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
	padding: 10px 14px;
	margin-bottom: 12px;
	border-inline-start: 4px solid var(--color-warning, #c28900);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	border-radius: var(--border-radius, 4px);
}

.required-gate-off-note p {
	flex: 1 1 280px;
	margin: 0;
}

.material-remove-btn {
	min-width: 32px;
	min-height: 32px;
	margin-inline-start: 6px;
	padding: 0 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 16px;
	line-height: 1;
	cursor: pointer;
}

.material-remove-btn:hover:not(:disabled),
.material-remove-btn:focus-visible {
	background: var(--color-background-hover);
}

.material-unavailable-reason {
	display: block;
	margin-top: 2px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
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

.material-btn:disabled,
.material-btn[aria-disabled='true'] {
	opacity: 0.5;
	cursor: not-allowed;
}

/* GitHub #75 (review): busy, but still focusable (aria-disabled, see setRequired) */
.cell-required input[aria-disabled='true'] {
	cursor: progress;
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

/* GitHub #75: the "Required" column (logical properties for RTL) */
.cell-required {
	white-space: nowrap;
	width: 1%;
	text-align: start;
}

.cell-required input[type='checkbox'] {
	inline-size: 18px;
	block-size: 18px;
	vertical-align: middle;
	accent-color: var(--color-primary-element);
}

.required-head {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
}

.reconfirm-btn {
	margin-inline-start: 8px;
}

.material-optional-hint {
	display: inline-block;
	margin-inline-start: 8px;
	padding: 0 8px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.material-file-link {
	color: var(--color-primary-element, #00679e);
	text-decoration: underline;
}

.material-file-unavailable {
	color: var(--color-text-maxcontrast, #767676);
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

/* Persona sweep C5 (#76 class): the fixed orange on light orange was 3.45:1 and ignored dark mode;
   Nextcloud's tint + its text colour, as the other status pills (statusColorContrast.test.js). */
.status-uploaded {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.status-extracted {
	background: var(--color-success);
	color: var(--color-success-text);
}

.status-error {
	background: var(--color-error);
	color: var(--color-error-text);
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
