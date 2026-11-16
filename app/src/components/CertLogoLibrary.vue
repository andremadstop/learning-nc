<template>
	<!-- GitHub #78: the certificate logo library in Administration → Learning. Admins upload the
		official variants of the logo once; instructors pick one per course under "Certificate design". -->
	<section class="cert-logo-library" aria-labelledby="learning-cert-logos-heading" data-test="cert-logo-library">
		<h3 id="learning-cert-logos-heading">
			{{ t('learning', 'Logos für Zertifikate') }}
		</h3>
		<p :id="limitsId" class="field-help">
			{{ t('learning', 'Lehrende können diese Logos in ihren Kursen unter „Gestaltung des Zertifikats“ statt des Logos dieser Nextcloud wählen, etwa das vollständige Logo, wenn Nextcloud nur das Favicon zeigt. PNG, JPEG oder WebP, höchstens 1 MB und 3000 × 3000 Pixel. SVG wird nicht angenommen, weil das Zertifikat auch auf der öffentlichen Prüfseite erscheint.') }}
		</p>

		<p v-if="loadError" class="cert-logo-library__error" role="alert">
			{{ loadError }}
		</p>
		<p v-else-if="loaded && logos.length === 0" class="field-help" data-test="cert-logo-library-empty">
			{{ t('learning', 'Noch keine Logos hinterlegt. Bis dahin tragen alle Zertifikate das Logo dieser Nextcloud.') }}
		</p>

		<ul v-if="logos.length" class="cert-logo-library__list">
			<li
				v-for="logo in logos"
				:key="logo.id"
				class="cert-logo-library__item"
				:data-test="'cert-logo-' + logo.id">
				<img :src="imageUrl(logo.id)" alt="" class="cert-logo-library__thumb">
				<div class="cert-logo-library__body">
					<label :for="'cert-logo-name-' + logo.id" class="hidden-visually">
						{{ t('learning', 'Name des Logos') }}
					</label>
					<input
						:id="'cert-logo-name-' + logo.id"
						v-model="names[logo.id]"
						type="text"
						class="cert-logo-library__name"
						:maxlength="nameMax"
						:disabled="busy"
						:data-test="'cert-logo-name-' + logo.id">
					<span class="field-help" :data-test="'cert-logo-usage-' + logo.id">
						{{ usageText(logo.courses) }}
					</span>
				</div>
				<div class="cert-logo-library__actions">
					<NcButton
						v-if="(names[logo.id] || '').trim() !== logo.name"
						variant="secondary"
						:disabled="busy"
						:data-test="'cert-logo-rename-' + logo.id"
						@click="rename(logo)">
						{{ t('learning', 'Namen speichern') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('learning', '„{title}“ löschen', { title: logo.name })"
						:data-test="'cert-logo-delete-' + logo.id"
						@click="confirming = logo.id">
						{{ t('learning', 'Löschen') }}
					</NcButton>
				</div>
				<div
					v-if="confirming === logo.id"
					class="cert-logo-library__confirm"
					role="group"
					:aria-label="t('learning', '„{title}“ löschen', { title: logo.name })"
					:data-test="'cert-logo-confirm-' + logo.id">
					<NcNoteCard :type="logo.courses > 0 ? 'warning' : 'info'">
						{{ deleteWarning(logo.courses) }}
					</NcNoteCard>
					<div class="cert-logo-library__actions">
						<NcButton
							variant="error"
							:disabled="busy"
							:data-test="'cert-logo-confirm-delete-' + logo.id"
							@click="remove(logo)">
							{{ t('learning', 'Logo endgültig löschen') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="confirming = ''">
							{{ t('learning', 'Abbrechen') }}
						</NcButton>
					</div>
				</div>
			</li>
		</ul>

		<p
			class="cert-logo-library__status"
			role="status"
			aria-live="polite"
			data-test="cert-logo-library-status">
			{{ status }}
		</p>

		<fieldset class="cert-logo-library__add" :disabled="!loaded || full">
			<legend>{{ t('learning', 'Logo hinzufügen') }}</legend>
			<p v-if="full" class="field-help" data-test="cert-logo-library-full">
				{{ t('learning', 'Die Bibliothek ist voll (höchstens {max} Logos). Lösche ein Logo, um ein neues hinzuzufügen.', { max }) }}
			</p>
			<label for="cert-logo-new-name">{{ t('learning', 'Name des Logos (z. B. Hauptlogo, Schriftzug)') }}</label>
			<input
				id="cert-logo-new-name"
				v-model="newName"
				type="text"
				class="cert-logo-library__name"
				:maxlength="nameMax"
				data-test="cert-logo-new-name">
			<!-- GitHub #72: the same field as stamp and signature, with its error right beside it -->
			<ImageFileField
				id-base="cert-logo-new"
				:label="t('learning', 'Datei')"
				:pick-label="t('learning', 'Logo hochladen …')"
				:described-by="limitsId"
				:error="uploadError"
				:busy="busy"
				:disabled="!loaded || full"
				test-id="cert-logo-new"
				pick-test-id="cert-logo-pick"
				@pick="pick" />
			<input
				ref="file"
				type="file"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				accept="image/png,image/jpeg,image/webp"
				data-test="cert-logo-file"
				@change="fileChosen">
		</fieldset>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ImageFileField from './ImageFileField.vue'
import {
	deleteLibraryLogo,
	libraryLogoImageUrl,
	listLibraryLogos,
	renameLibraryLogo,
	uploadLibraryLogo,
} from '../services/CertificateService.js'

/** CertLogoLibrary::MAX_BYTES: a larger file is refused before it is sent */
export const LOGO_MAX_BYTES = 1048576
/** CertLogoLibrary::NAME_MAX */
const NAME_MAX = 80
/** what the server accepts (checked there by content); SVG never */
export const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp']

/**
 * The message for a refused logo (CertLogoController error codes).
 * @param {string} code - the error code of the answer
 * @param {number} max - the library's limit
 * @return {string}
 */
export function logoErrorMessage(code, max = 20) {
	switch (code) {
	case 'unsupported_type':
		return t('learning', 'Die Datei ist kein PNG-, JPEG- oder WebP-Bild. SVG wird nicht angenommen. Liegt das Logo nur als SVG vor, exportiere es in einem Grafikprogramm als PNG (etwa 1000 Pixel breit) oder frag die Person, die es gestaltet hat, nach einer PNG-Datei.')
	case 'too_large':
		return t('learning', 'Die Datei ist zu groß (höchstens 1 MB und 3000 × 3000 Pixel).')
	case 'duplicate':
		return t('learning', 'Dieses Logo ist schon in der Bibliothek.')
	case 'limit_reached':
		return t('learning', 'Die Bibliothek ist voll (höchstens {max} Logos). Lösche ein Logo, um ein neues hinzuzufügen.', { max })
	case 'invalid_name':
		return t('learning', 'Bitte einen Namen mit höchstens 80 Zeichen eingeben.')
	case 'no_file':
		return t('learning', 'Es ist keine Datei angekommen. Bitte erneut versuchen.')
	default:
		return t('learning', 'Das Logo konnte nicht hochgeladen werden.')
	}
}

export default {
	name: 'CertLogoLibrary',

	components: {
		ImageFileField,
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			logos: [],
			names: {},
			max: 20,
			loaded: false,
			loadError: '',
			busy: false,
			confirming: '',
			newName: '',
			uploadError: '',
			status: '',
			nameMax: NAME_MAX,
			limitsId: 'cert-logo-library-limits',
		}
	},

	computed: {
		full() {
			return this.logos.length >= this.max
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		imageUrl(id) {
			return libraryLogoImageUrl(id)
		},

		apply(data) {
			this.logos = Array.isArray(data?.logos) ? data.logos.filter((logo) => typeof logo?.id === 'string') : []
			this.max = Number.isInteger(data?.max) ? data.max : this.max
			const names = {}
			for (const logo of this.logos) {
				names[logo.id] = logo.name
			}
			this.names = names
		},

		async load() {
			this.loadError = ''
			try {
				this.apply(await listLibraryLogos())
				this.loaded = true
			} catch (e) {
				this.loadError = t('learning', 'Die Logos konnten nicht geladen werden.')
			}
		},

		usageText(count) {
			const courses = Number(count) || 0
			if (courses === 0) {
				return t('learning', 'Von keinem Kurs verwendet')
			}
			return n('learning', 'Von %n Kurs verwendet', 'Von %n Kursen verwendet', courses)
		},

		deleteWarning(count) {
			const courses = Number(count) || 0
			if (courses === 0) {
				return t('learning', 'Das Logo wird aus der Bibliothek entfernt. Bereits ausgestellte Zertifikate behalten ihr Logo.')
			}
			return n(
				'learning',
				'%n Kurs verwendet dieses Logo. Seine neuen Zertifikate tragen danach wieder das Logo dieser Nextcloud. Bereits ausgestellte Zertifikate behalten ihr Logo.',
				'%n Kurse verwenden dieses Logo. Ihre neuen Zertifikate tragen danach wieder das Logo dieser Nextcloud. Bereits ausgestellte Zertifikate behalten ihr Logo.',
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
				this.uploadError = t('learning', 'Bitte zuerst einen Namen für das Logo eingeben.')
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
			if (file.size > LOGO_MAX_BYTES) {
				this.uploadError = logoErrorMessage('too_large', this.max)
				return
			}
			if (file.type && !LOGO_TYPES.includes(file.type)) {
				this.uploadError = logoErrorMessage('unsupported_type', this.max)
				return
			}
			const name = this.newName.trim()
			this.busy = true
			try {
				this.apply(await uploadLibraryLogo(name, file))
				this.newName = ''
				this.status = t('learning', '„{title}“ wurde hinzugefügt.', { title: name })
			} catch (e) {
				this.uploadError = logoErrorMessage(e?.response?.data?.error, this.max)
			} finally {
				this.busy = false
			}
		},

		async rename(logo) {
			this.busy = true
			this.status = ''
			try {
				this.apply(await renameLibraryLogo(logo.id, (this.names[logo.id] || '').trim()))
				this.status = t('learning', 'Name gespeichert.')
			} catch (e) {
				this.status = logoErrorMessage(e?.response?.data?.error, this.max)
			} finally {
				this.busy = false
			}
		},

		async remove(logo) {
			this.busy = true
			this.status = ''
			try {
				const data = await deleteLibraryLogo(logo.id)
				this.apply(data)
				this.confirming = ''
				const reset = Number(data?.courses_reset) || 0
				this.status = reset > 0
					? n('learning', '„{title}“ wurde gelöscht. %n Kurs trägt jetzt wieder das Logo dieser Nextcloud.', '„{title}“ wurde gelöscht. %n Kurse tragen jetzt wieder das Logo dieser Nextcloud.', reset, { title: logo.name })
					: t('learning', '„{title}“ wurde gelöscht.', { title: logo.name })
			} catch (e) {
				this.status = t('learning', 'Das Logo konnte nicht gelöscht werden.')
				this.load()
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.cert-logo-library {
	display: grid;
	gap: 12px;
	margin-top: 24px;
}

.cert-logo-library h3 {
	margin: 0;
}

.field-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.cert-logo-library__list {
	display: grid;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.cert-logo-library__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

/* the logo is printed on white paper: show it on white in dark mode too */
.cert-logo-library__thumb {
	max-height: 48px;
	max-width: 140px;
	object-fit: contain;
	background: #fff;
	border-radius: var(--border-radius, 4px);
	padding: 4px;
}

.cert-logo-library__body {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1 1 200px;
	min-width: 0;
}

.cert-logo-library__name {
	width: 100%;
	max-width: 360px;
	margin-inline: 0;
	box-sizing: border-box;
}

.cert-logo-library__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.cert-logo-library__confirm {
	flex-basis: 100%;
	display: grid;
	gap: 8px;
}

.cert-logo-library__add {
	display: grid;
	gap: 8px;
	border: none;
	padding: 0;
	margin: 0;
}

.cert-logo-library__add legend {
	font-weight: 600;
	padding: 0;
	margin-bottom: 4px;
}

.cert-logo-library__add > label {
	font-weight: 600;
	font-size: 0.9em;
}

.cert-logo-library__status {
	margin: 0;
}

.cert-logo-library__error {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}
</style>
