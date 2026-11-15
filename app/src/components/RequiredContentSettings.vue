<template>
	<div class="required-content-settings" data-test="required-content">
		<h3>{{ t('learning', 'Pflichtinhalte') }}</h3>
		<p class="required-content-hint">
			{{ t('learning', 'Dokumente, die Lernende als gelesen bestätigen, und Videos, die sie ansehen. Lernende finden sie im Lernraum unter „Pflichtinhalte“. Die Bestätigungen stehen im Abdeckungsbericht.') }}
		</p>

		<NcCheckboxRadioSwitch
			:model-value="gateEnabled"
			:disabled="savingGate"
			data-test="required-content-gate"
			@update:model-value="setGate(!!$event)">
			{{ t('learning', 'Prüfung erst nach Abschluss aller Pflichtinhalte') }}
		</NcCheckboxRadioSwitch>
		<p class="required-content-hint">
			{{ t('learning', 'Gesperrt wird nur die Prüfung, die für das Zertifikat zählt. Training, Leitner und Probeprüfungen bleiben offen.') }}
		</p>
		<NcNoteCard v-if="gateEnabled && loaded && items.length === 0" type="warning" data-test="required-content-gate-empty">
			{{ t('learning', 'Es gibt noch keine Pflichtinhalte. Solange die Liste leer ist, sperrt der Schalter nichts.') }}
		</NcNoteCard>

		<p v-if="loadError" class="required-content-error" role="alert">
			{{ loadError }}
		</p>

		<ul v-if="items.length" class="required-content-list" data-test="required-content-list">
			<li v-for="item in items" :key="item.id" class="required-content-item">
				<span class="required-content-type">{{ typeLabel(item.source_type) }}</span>
				<span class="required-content-title">{{ itemTitle(item) }}</span>
				<span v-if="isUnservableDocument(item)" class="required-content-warning">
					{{ t('learning', 'Nicht im Materialordner: Lernende können es nicht öffnen.') }}
				</span>
				<NcButton
					variant="tertiary"
					:disabled="removing[item.id] === true"
					:aria-label="t('learning', '„{title}“ entfernen', { title: itemTitle(item) })"
					@click="removeItem(item)">
					{{ t('learning', 'Entfernen') }}
				</NcButton>
			</li>
		</ul>
		<p v-else-if="loaded && !loadError" class="required-content-hint">
			{{ t('learning', 'Noch keine Pflichtinhalte.') }}
		</p>

		<!-- Documents: only files of the material folder, which members can open -->
		<div class="required-content-add">
			<h4>{{ t('learning', 'Dokument hinzufügen') }}</h4>
			<p v-if="!course?.material_folder" class="required-content-hint">
				{{ t('learning', 'Pflichtdokumente kommen aus dem Materialordner des Kurses. Verknüpfe ihn im Lernraum unter „Lernmaterial“ und scanne ihn.') }}
			</p>
			<template v-else>
				<label for="required-doc-select">{{ t('learning', 'Datei aus dem Materialordner') }}</label>
				<select
					id="required-doc-select"
					v-model="docForm.documentId"
					class="required-content-input"
					data-test="required-doc-select">
					<option value="">
						{{ t('learning', 'Datei wählen …') }}
					</option>
					<option v-for="doc in addableDocs" :key="doc.id" :value="String(doc.id)">
						{{ doc.file_name }}
					</option>
				</select>
				<p v-if="loaded && addableDocs.length === 0" class="required-content-hint">
					{{ t('learning', 'Keine weitere Datei im Materialordner. Neue Dateien erscheinen nach „Ordner scannen“ im Lernraum unter „Lernmaterial“.') }}
				</p>
				<label for="required-doc-title">{{ t('learning', 'Titel (optional)') }}</label>
				<input
					id="required-doc-title"
					v-model="docForm.title"
					type="text"
					maxlength="255"
					class="required-content-input">
				<NcButton
					variant="secondary"
					:disabled="!docForm.documentId || adding"
					data-test="required-doc-add"
					@click="addDocument">
					{{ t('learning', 'Dokument hinzufügen') }}
				</NcButton>
			</template>
		</div>

		<div class="required-content-add">
			<h4>{{ t('learning', 'Video hinzufügen') }}</h4>
			<label for="required-video-source">{{ t('learning', 'Quelle') }}</label>
			<select
				id="required-video-source"
				v-model="videoForm.sourceType"
				class="required-content-input"
				data-test="required-video-source">
				<option v-if="isOwner" value="nc-files">
					{{ t('learning', 'Video aus meinen Dateien') }}
				</option>
				<option value="youtube-nocookie">
					YouTube
				</option>
				<option value="vimeo">
					Vimeo
				</option>
			</select>
			<p v-if="!isOwner" class="required-content-hint">
				{{ t('learning', 'Videos aus den Dateien kann nur die Kursleitung hinzufügen, denn sie liegen in ihren Dateien.') }}
			</p>

			<template v-if="videoForm.sourceType === 'nc-files'">
				<div class="required-content-row">
					<span class="required-content-picked">{{ videoForm.ref || t('learning', 'Kein Video gewählt') }}</span>
					<NcButton variant="secondary" @click="pickVideo">
						{{ t('learning', 'Video auswählen …') }}
					</NcButton>
				</div>
			</template>
			<template v-else>
				<label for="required-video-id">{{ t('learning', 'Video-ID beim Anbieter') }}</label>
				<input
					id="required-video-id"
					v-model="videoForm.ref"
					type="text"
					maxlength="300"
					dir="ltr"
					class="required-content-input"
					:aria-invalid="embedIdInvalid ? 'true' : null"
					:aria-describedby="embedIdInvalid ? 'required-video-id-hint' : null"
					data-test="required-video-id">
				<p
					v-if="embedIdInvalid"
					id="required-video-id-hint"
					class="required-content-hint"
					data-test="required-video-id-invalid">
					{{ t('learning', 'Darin steht keine gültige Video-ID. Füge die Adresse des Videos oder nur seine ID ein.') }}
				</p>
			</template>

			<label for="required-video-title">{{ t('learning', 'Titel (optional)') }}</label>
			<input
				id="required-video-title"
				v-model="videoForm.title"
				type="text"
				maxlength="255"
				class="required-content-input">

			<fieldset class="required-content-duration">
				<legend>{{ t('learning', 'Länge des Videos') }}</legend>
				<label for="required-video-minutes">{{ t('learning', 'Minuten') }}</label>
				<input
					id="required-video-minutes"
					v-model="videoForm.minutes"
					type="number"
					min="0"
					max="600"
					inputmode="numeric"
					class="required-content-number"
					data-test="required-video-minutes">
				<label for="required-video-seconds">{{ t('learning', 'Sekunden') }}</label>
				<input
					id="required-video-seconds"
					v-model="videoForm.seconds"
					type="number"
					min="0"
					max="59"
					inputmode="numeric"
					class="required-content-number">
			</fieldset>
			<p class="required-content-hint">
				{{ t('learning', 'Ein Video gilt als angesehen, wenn 95 % dieser Länge abgespielt wurden.') }}
			</p>
			<NcButton
				variant="secondary"
				:disabled="!videoReady || adding"
				data-test="required-video-add"
				@click="addVideo">
				{{ t('learning', 'Video hinzufügen') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { parseDocumentsResponse } from '../utils/courseMaterials.js'
import {
	addableDocuments,
	documentIdFromRef,
	documentPayload,
	providerVideoId,
	videoFormComplete,
	videoPayload,
} from '../utils/requiredContent.js'

function emptyVideoForm(sourceType) {
	return { sourceType, ref: '', title: '', minutes: '', seconds: '' }
}

export default {
	name: 'RequiredContentSettings',

	components: {
		NcButton,
		NcNoteCard,
		NcCheckboxRadioSwitch,
	},

	props: {
		courseId: { type: Number, required: true },
		course: { type: Object, default: null },
	},

	emits: ['error', 'refresh-course-detail'],

	data() {
		return {
			items: [],
			documents: [],
			loaded: false,
			loadError: '',
			gateEnabled: this.course?.video_gate_enabled === true,
			savingGate: false,
			adding: false,
			removing: {},
			docForm: { documentId: '', title: '' },
			videoForm: emptyVideoForm('youtube-nocookie'),
		}
	},

	computed: {
		isOwner() {
			const uid = getCurrentUser()?.uid
			return !!uid && uid === this.course?.instructor_id
		},
		addableDocs() {
			return addableDocuments(this.documents, this.items)
		},
		videoReady() {
			return videoFormComplete(this.videoForm)
		},
		embedIdInvalid() {
			return this.videoForm.sourceType !== 'nc-files'
				&& String(this.videoForm.ref || '').trim() !== ''
				&& providerVideoId(this.videoForm.sourceType, this.videoForm.ref) === null
		},
		documentsById() {
			const map = {}
			for (const doc of this.documents) {
				map[Number(doc.id)] = doc
			}
			return map
		},
	},

	watch: {
		'course.video_gate_enabled'(value) {
			this.gateEnabled = value === true
		},
	},

	mounted() {
		if (this.isOwner) {
			this.videoForm = emptyVideoForm('nc-files')
		}
		this.load()
	},

	methods: {
		t,

		async load() {
			this.loadError = ''
			try {
				const [videos, docs] = await Promise.all([
					axios.get(generateUrl('/apps/learning/api/courses/{courseId}/videos', { courseId: this.courseId })),
					this.course?.material_folder
						? axios.get(generateUrl('/apps/learning/api/courses/{courseId}/documents', { courseId: this.courseId }))
						: Promise.resolve({ data: [] }),
				])
				this.items = Array.isArray(videos.data) ? videos.data : []
				this.documents = parseDocumentsResponse(docs.data).documents
			} catch (e) {
				this.loadError = t('learning', 'Die Pflichtinhalte konnten nicht geladen werden.')
			} finally {
				this.loaded = true
			}
		},

		typeLabel(sourceType) {
			switch (sourceType) {
			case 'document': return t('learning', 'Dokument')
			case 'nc-files': return t('learning', 'Video')
			case 'youtube-nocookie': return 'YouTube'
			case 'vimeo': return 'Vimeo'
			default: return sourceType
			}
		},

		itemTitle(item) {
			if (item.title) {
				return item.title
			}
			if (item.source_type === 'document') {
				const doc = this.documentsById[documentIdFromRef(item.video_ref)]
				return doc?.file_name || t('learning', 'Pflichtdokument')
			}
			return t('learning', 'Pflichtvideo')
		},

		isUnservableDocument(item) {
			if (item.source_type !== 'document' || !this.loaded) {
				return false
			}
			const doc = this.documentsById[documentIdFromRef(item.video_ref)]
			return !doc || doc.downloadable !== true
		},

		async setGate(enabled) {
			const before = this.gateEnabled
			this.gateEnabled = enabled
			this.savingGate = true
			try {
				await axios.put(generateUrl('/apps/learning/api/courses/{courseId}/video-gate', { courseId: this.courseId }), { enabled })
				this.$emit('refresh-course-detail')
			} catch (e) {
				this.gateEnabled = before
				this.$emit('error', t('learning', 'Die Einstellung konnte nicht gespeichert werden.'))
			} finally {
				this.savingGate = false
			}
		},

		async create(payload) {
			this.adding = true
			try {
				const response = await axios.post(generateUrl('/apps/learning/api/courses/{courseId}/videos', { courseId: this.courseId }), payload)
				this.items = [...this.items, response.data]
				this.$emit('refresh-course-detail')
				return true
			} catch (e) {
				this.$emit('error', t('learning', 'Der Pflichtinhalt konnte nicht hinzugefügt werden.'))
				return false
			} finally {
				this.adding = false
			}
		},

		async addDocument() {
			const doc = this.documentsById[Number(this.docForm.documentId)]
			if (!doc) {
				return
			}
			if (await this.create(documentPayload(doc, this.docForm.title, this.items.length))) {
				this.docForm = { documentId: '', title: '' }
			}
		},

		async addVideo() {
			if (!this.videoReady) {
				return
			}
			if (await this.create(videoPayload(this.videoForm, this.items.length))) {
				this.videoForm = emptyVideoForm(this.videoForm.sourceType)
			}
		},

		async removeItem(item) {
			this.removing = { ...this.removing, [item.id]: true }
			try {
				await axios.delete(generateUrl('/apps/learning/api/course-videos/{id}', { id: item.id }))
				this.items = this.items.filter((row) => row.id !== item.id)
				this.$emit('refresh-course-detail')
			} catch (e) {
				this.$emit('error', t('learning', 'Der Pflichtinhalt konnte nicht entfernt werden.'))
			} finally {
				this.removing = { ...this.removing, [item.id]: false }
			}
		},

		async pickVideo() {
			let chosen = ''
			try {
				// loaded on use: the settings page needs the picker only for this button
				const { getFilePickerBuilder } = await import('@nextcloud/dialogs')
				// The picker hands back a choice only through one of its buttons; without a button
				// factory it has none and can only be closed, which picks nothing (@nextcloud/dialogs 8).
				const picker = getFilePickerBuilder(t('learning', 'Video auswählen'))
					.setMultiSelect(false)
					.setMimeTypeFilter(['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-matroska', 'video/mpeg', 'video/x-msvideo'])
					.setButtonFactory((selected) => [{
						label: t('learning', 'Dieses Video übernehmen'),
						variant: 'primary',
						disabled: !selected[0]?.path,
						callback: (nodes) => {
							chosen = nodes[0]?.path || ''
						},
					}])
					.build()
				await picker.pickNodes()
			} catch (e) {
				// closed without a choice
				return
			}
			if (chosen !== '') {
				this.videoForm = { ...this.videoForm, ref: chosen }
			}
		},
	},
}
</script>

<style scoped>
.required-content-settings h3 {
	margin: 0 0 8px;
}

.required-content-hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 8px;
}

.required-content-list {
	list-style: none;
	margin: 12px 0;
	padding: 0;
}

.required-content-item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.required-content-type {
	font-size: 0.85em;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.required-content-title {
	flex: 1 1 200px;
	min-width: 0;
	overflow-wrap: anywhere;
}

.required-content-warning {
	flex-basis: 100%;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
	font-size: 0.9em;
}

.required-content-error {
	color: var(--color-error-text, var(--color-error));
}

.required-content-add {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 6px;
	margin-top: 20px;
	max-width: 480px;
}

.required-content-add h4 {
	margin: 0;
}

.required-content-input {
	width: 100%;
	max-width: 480px;
}

.required-content-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.required-content-picked {
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
}

.required-content-duration {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 10px;
	border: none;
	margin: 0;
	padding: 0;
}

.required-content-duration legend {
	padding: 0;
	margin-bottom: 4px;
}

.required-content-number {
	width: 90px;
}
</style>
