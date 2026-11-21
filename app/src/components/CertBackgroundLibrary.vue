<template>
	<!-- GitHub #93: the certificate background library in Administration → Learning, like the logo
		library (#78). Admins upload backgrounds once; instructors pick one per course under
		"Certificate design", or one of the built-in backgrounds. -->
	<section class="cert-bg-library" aria-labelledby="learning-cert-backgrounds-heading" data-test="cert-background-library">
		<h3 id="learning-cert-backgrounds-heading">
			{{ t('learning', 'Hintergründe für Zertifikate') }}
		</h3>
		<p :id="limitsId" class="field-help">
			{{ t('learning', 'Lehrende können diese Hintergründe in ihren Kursen unter „Gestaltung des Zertifikats“ wählen, etwa einen Rahmen oder Ornamente in den Farben der Organisation. PNG, JPEG oder WebP, höchstens 2 MB und 4000 Pixel je Seite. SVG wird nicht angenommen. Jedes Bild wird beim Hochladen neu gespeichert; eingebettete Angaben wie Kamera- oder Ortsdaten werden dabei entfernt.') }}
		</p>

		<p v-if="loadError" class="cert-bg-library__error" role="alert">
			{{ loadError }}
		</p>
		<p v-else-if="loaded && backgrounds.length === 0" class="field-help" data-test="cert-background-library-empty">
			{{ t('learning', 'Noch keine Hintergründe hinterlegt. Lehrende können bis dahin einen der mitgelieferten Hintergründe wählen.') }}
		</p>

		<ul v-if="backgrounds.length" class="cert-bg-library__list">
			<li
				v-for="background in backgrounds"
				:key="background.id"
				class="cert-bg-library__item"
				:data-test="'cert-background-' + background.id">
				<img :src="imageUrl(background.id)" alt="" class="cert-bg-library__thumb">
				<div class="cert-bg-library__body">
					<label :for="'cert-background-name-' + background.id" class="hidden-visually">
						{{ t('learning', 'Name des Hintergrunds') }}
					</label>
					<input
						:id="'cert-background-name-' + background.id"
						v-model="names[background.id]"
						type="text"
						class="cert-bg-library__name"
						:maxlength="nameMax"
						:disabled="busy"
						:data-test="'cert-background-name-' + background.id">
					<span class="field-help" :data-test="'cert-background-usage-' + background.id">
						{{ usageText(background.courses) }}
					</span>
				</div>
				<div class="cert-bg-library__actions">
					<NcButton
						v-if="(names[background.id] || '').trim() !== background.name"
						variant="secondary"
						:disabled="busy"
						:data-test="'cert-background-rename-' + background.id"
						@click="rename(background)">
						{{ t('learning', 'Namen speichern') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('learning', '„{title}“ löschen', { title: background.name }, undefined, RAW)"
						:data-test="'cert-background-delete-' + background.id"
						@click="confirming = background.id">
						{{ t('learning', 'Löschen') }}
					</NcButton>
				</div>
				<div
					v-if="confirming === background.id"
					class="cert-bg-library__confirm"
					role="group"
					:aria-label="t('learning', '„{title}“ löschen', { title: background.name }, undefined, RAW)"
					:data-test="'cert-background-confirm-' + background.id">
					<NcNoteCard :type="background.courses > 0 ? 'warning' : 'info'">
						{{ deleteWarning(background.courses) }}
					</NcNoteCard>
					<div class="cert-bg-library__actions">
						<NcButton
							variant="error"
							:disabled="busy"
							:data-test="'cert-background-confirm-delete-' + background.id"
							@click="remove(background)">
							{{ t('learning', 'Hintergrund endgültig löschen') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="confirming = ''">
							{{ t('learning', 'Abbrechen') }}
						</NcButton>
					</div>
				</div>
			</li>
		</ul>

		<p
			class="cert-bg-library__status"
			role="status"
			aria-live="polite"
			data-test="cert-background-library-status">
			{{ status }}
		</p>

		<fieldset class="cert-bg-library__add" :disabled="!loaded || full">
			<legend>{{ t('learning', 'Hintergrund hinzufügen') }}</legend>
			<p v-if="full" class="field-help" data-test="cert-background-library-full">
				{{ fullText }}
			</p>
			<label for="cert-background-new-name">{{ t('learning', 'Name des Hintergrunds (z. B. Goldrahmen)') }}</label>
			<input
				id="cert-background-new-name"
				v-model="newName"
				type="text"
				class="cert-bg-library__name"
				:maxlength="nameMax"
				data-test="cert-background-new-name">
			<ImageFileField
				id-base="cert-background-new"
				:label="t('learning', 'Datei')"
				:pick-label="t('learning', 'Hintergrund hochladen …')"
				:described-by="limitsId"
				:error="uploadError"
				:busy="busy"
				:disabled="!loaded || full"
				test-id="cert-background-new"
				pick-test-id="cert-background-pick"
				@pick="pick" />
			<input
				ref="file"
				type="file"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				accept="image/png,image/jpeg,image/webp"
				data-test="cert-background-file"
				@change="fileChosen">
		</fieldset>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ImageFileField from './ImageFileField.vue'
import {
	deleteLibraryBackground,
	libraryBackgroundImageUrl,
	listLibraryBackgrounds,
	renameLibraryBackground,
	uploadLibraryBackground,
} from '../services/CertificateService.js'

/** CertBackgroundLibrary::MAX_BYTES: a larger file is refused before it is sent */
export const BACKGROUND_MAX_BYTES = 2097152
/** CertImageLibrary::NAME_MAX */
const NAME_MAX = 80
/** Names in texts: shown as text (interpolation, attributes), never as HTML. */
const RAW = { escape: false, sanitize: false }
/** what the server accepts (checked there by content); SVG never */
export const BACKGROUND_TYPES = ['image/png', 'image/jpeg', 'image/webp']

/**
 * @param {number} max - the library's limit
 * @return {string}
 */
function fullMessage(max) {
	return t('learning', 'Die Bibliothek ist voll (höchstens {max} Hintergründe). Lösche einen Hintergrund, um einen neuen hinzuzufügen.', { max })
}

/**
 * The message for a refused background (CertBackgroundController error codes).
 * @param {string} code - the error code of the answer
 * @param {number} max - the library's limit
 * @param {boolean} reloaded - for a conflict: whether loading the list again worked (GitHub #102)
 * @return {string}
 */
export function backgroundErrorMessage(code, max = 20, reloaded = true) {
	switch (code) {
	case 'unsupported_type':
		return t('learning', 'Die Datei ist kein PNG-, JPEG- oder WebP-Bild. SVG wird nicht angenommen. Liegt der Hintergrund nur als SVG oder PDF vor, exportiere ihn in einem Grafikprogramm als PNG oder JPEG (für A4 quer etwa 3500 Pixel breit).')
	case 'too_large':
		return t('learning', 'Die Datei ist zu groß (höchstens 2 MB und 4000 Pixel je Seite). Als JPEG gespeichert ist ein Foto meist deutlich kleiner.')
	case 'duplicate':
		return t('learning', 'Dieser Hintergrund ist schon in der Bibliothek.')
	case 'limit_reached':
		return fullMessage(max)
	case 'invalid_name':
		return t('learning', 'Bitte einen Namen mit höchstens 80 Zeichen eingeben.')
	case 'no_file':
		return t('learning', 'Es ist keine Datei angekommen. Bitte erneut versuchen.')
	case 'library_changed':
		return reloaded
			? t('learning', 'Die Liste wurde inzwischen geändert, etwa von einer anderen Person mit Administrationsrechten. Sie ist jetzt neu geladen. Bitte prüfe sie und versuche es dann erneut.')
			: t('learning', 'Die Liste wurde inzwischen geändert, etwa von einer anderen Person mit Administrationsrechten. Sie konnte nicht neu geladen werden. Bitte lade die Seite neu und versuche es dann erneut.')
	case 'library_busy':
		return reloaded
			? t('learning', 'Die Liste wird gerade an anderer Stelle geändert. Sie ist jetzt neu geladen. Bitte versuche es gleich noch einmal.')
			: t('learning', 'Die Liste wird gerade an anderer Stelle geändert. Sie konnte nicht neu geladen werden. Bitte lade die Seite neu und versuche es dann erneut.')
	default:
		return t('learning', 'Der Hintergrund konnte nicht hochgeladen werden.')
	}
}

/**
 * GitHub #102: the answers (409) to a change made on a list that is no longer the one stored:
 * the list is loaded again, nothing is retried or overwritten.
 * @param {string} code - the error code of the answer
 * @return {boolean}
 */
export function isLibraryConflict(code) {
	return code === 'library_changed' || code === 'library_busy'
}

export default {
	name: 'CertBackgroundLibrary',

	components: {
		ImageFileField,
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			RAW,
			backgrounds: [],
			names: {},
			max: 20,
			loaded: false,
			loadError: '',
			busy: false,
			confirming: '',
			newName: '',
			uploadError: '',
			status: '',
			/** GitHub #102: the revision of the list shown, sent back with a rename or delete */
			revision: '',
			nameMax: NAME_MAX,
			limitsId: 'cert-background-library-limits',
		}
	},

	computed: {
		full() {
			return this.backgrounds.length >= this.max
		},
		fullText() {
			return fullMessage(this.max)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		imageUrl(id) {
			return libraryBackgroundImageUrl(id)
		},

		apply(data) {
			this.backgrounds = Array.isArray(data?.backgrounds) ? data.backgrounds.filter((entry) => typeof entry?.id === 'string') : []
			this.max = Number.isInteger(data?.max) ? data.max : this.max
			this.revision = typeof data?.revision === 'string' ? data.revision : ''
			const names = {}
			for (const entry of this.backgrounds) {
				names[entry.id] = entry.name
			}
			this.names = names
		},

		/**
		 * @return {Promise<boolean>} whether the list could be loaded
		 */
		async load() {
			this.loadError = ''
			try {
				this.apply(await listLibraryBackgrounds())
				this.loaded = true
				return true
			} catch (e) {
				this.loadError = t('learning', 'Die Hintergründe konnten nicht geladen werden.')
				return false
			}
		},

		usageText(count) {
			const courses = Number(count) || 0
			if (courses === 0) {
				return t('learning', 'Von keinem Kurs verwendet')
			}
			return n('learning', 'Von %n Kurs verwendet', 'Von %n Kursen verwendet', courses)
		},

		/** Said before deleting: how many courses lose the background, and that issued certificates keep it. */
		deleteWarning(count) {
			const courses = Number(count) || 0
			if (courses === 0) {
				return t('learning', 'Der Hintergrund wird aus der Bibliothek entfernt. Bereits ausgestellte Zertifikate behalten ihren Hintergrund.')
			}
			return n(
				'learning',
				'Wird in %n Kurs verwendet. Dieser Kurs erhält keinen Hintergrund mehr; ausgestellte Zertifikate behalten ihren.',
				'Wird in %n Kursen verwendet. Diese Kurse erhalten keinen Hintergrund mehr; ausgestellte Zertifikate behalten ihren.',
				courses,
			)
		},

		/** "Upload": first the name, then the file dialog of the browser. */
		async pick() {
			this.uploadError = ''
			this.status = ''
			if (!this.newName.trim()) {
				// let the cleared live region reach the DOM, so the same message is announced again
				await this.$nextTick()
				this.uploadError = t('learning', 'Bitte zuerst einen Namen für den Hintergrund eingeben.')
				return
			}
			this.$refs.file?.click()
		},

		async fileChosen(event) {
			const input = event?.target
			const file = input?.files?.[0]
			if (input) {
				// the same file can be chosen again after a refusal
				input.value = ''
			}
			if (!file) {
				return
			}
			await this.upload(file)
		},

		async upload(file) {
			this.uploadError = ''
			this.status = ''
			await this.$nextTick()
			// Size and declared type are known in the browser: refused at once, without a request.
			// The server checks the content again (an SVG renamed to .png is refused there).
			if (file.size > BACKGROUND_MAX_BYTES) {
				this.uploadError = backgroundErrorMessage('too_large', this.max)
				return
			}
			if (file.type && !BACKGROUND_TYPES.includes(file.type)) {
				this.uploadError = backgroundErrorMessage('unsupported_type', this.max)
				return
			}
			const name = this.newName.trim()
			this.busy = true
			try {
				this.apply(await uploadLibraryBackground(name, file))
				this.newName = ''
				this.status = t('learning', '„{title}“ wurde hinzugefügt.', { title: name }, undefined, RAW)
			} catch (e) {
				const code = e?.response?.data?.error
				if (isLibraryConflict(code)) {
					// said only once it is known whether the list shown is the one stored now
					this.uploadError = backgroundErrorMessage(code, this.max, await this.load())
				} else {
					this.uploadError = backgroundErrorMessage(code, this.max)
				}
			} finally {
				this.busy = false
			}
		},

		async rename(background) {
			this.busy = true
			this.status = ''
			try {
				this.apply(await renameLibraryBackground(background.id, (this.names[background.id] || '').trim(), this.revision))
				this.status = t('learning', 'Name gespeichert.')
			} catch (e) {
				const code = e?.response?.data?.error
				if (isLibraryConflict(code)) {
					// shown as stored now; the name typed belonged to the list that is gone
					this.status = backgroundErrorMessage(code, this.max, await this.load())
				} else {
					this.status = backgroundErrorMessage(code, this.max)
				}
			} finally {
				this.busy = false
			}
		},

		async remove(background) {
			this.busy = true
			this.status = ''
			try {
				const data = await deleteLibraryBackground(background.id, this.revision)
				this.apply(data)
				this.confirming = ''
				const reset = Number(data?.courses_reset) || 0
				this.status = reset > 0
					? n('learning', '„{title}“ wurde gelöscht. %n Kurs hat jetzt keinen Hintergrund mehr.', '„{title}“ wurde gelöscht. %n Kurse haben jetzt keinen Hintergrund mehr.', reset, { title: background.name }, RAW)
					: t('learning', '„{title}“ wurde gelöscht.', { title: background.name }, undefined, RAW)
			} catch (e) {
				const code = e?.response?.data?.error
				if (isLibraryConflict(code)) {
					this.confirming = ''
					this.status = backgroundErrorMessage(code, this.max, await this.load())
				} else {
					this.status = t('learning', 'Der Hintergrund konnte nicht gelöscht werden.')
					this.load()
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.cert-bg-library {
	display: grid;
	gap: 12px;
	margin-top: 24px;
}

.cert-bg-library h3 {
	margin: 0;
}

.field-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.cert-bg-library__list {
	display: grid;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.cert-bg-library__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.cert-bg-library__thumb {
	width: 120px;
	height: 80px;
	object-fit: cover;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: #fff;
}

.cert-bg-library__body {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1 1 200px;
	min-width: 0;
}

.cert-bg-library__name {
	width: 100%;
	max-width: 360px;
	margin-inline: 0;
	box-sizing: border-box;
}

.cert-bg-library__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.cert-bg-library__confirm {
	flex-basis: 100%;
	display: grid;
	gap: 8px;
}

.cert-bg-library__add {
	display: grid;
	gap: 8px;
	border: none;
	padding: 0;
	margin: 0;
}

.cert-bg-library__add legend {
	font-weight: 600;
	padding: 0;
	margin-bottom: 4px;
}

.cert-bg-library__add > label {
	font-weight: 600;
	font-size: 0.9em;
}

.cert-bg-library__status {
	margin: 0;
}

.cert-bg-library__error {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}
</style>
