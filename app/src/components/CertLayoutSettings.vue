<template>
	<!-- GitHub #43: how the course's certificates look. Text, switches and signer are saved with the
		certification settings (parent's Save); stamp and signature are taken over immediately. -->
	<div class="cert-layout" data-test="cert-layout">
		<h4>{{ t('learning', 'Gestaltung des Zertifikats') }}</h4>
		<p class="mode-config-hint">
			{{ t('learning', 'Bereits ausgestellte Zertifikate behalten ihre Gestaltung. Änderungen gelten für Zertifikate, die nach dem Speichern ausgestellt werden.') }}
		</p>

		<div class="cert-config-field">
			<label :for="introId">{{ t('learning', 'Einleitungstext') }}</label>
			<textarea
				:id="introId"
				ref="intro"
				:value="modelValue.intro"
				rows="5"
				:maxlength="introMax"
				class="cert-layout-intro"
				data-test="cert-layout-intro"
				:placeholder="t('learning', 'Leer lassen für den Standardtext „Verliehen an: Name“.')"
				:aria-describedby="introHintId"
				@input="update({ intro: $event.target.value })" />
			<p :id="introHintId" class="mode-config-hint">
				{{ t('learning', 'Platzhalter werden beim Anzeigen durch die Angaben aus dem signierten Zertifikat ersetzt. Eine Zeile, die nur {placeholder} enthält, wird zur Liste der Kursbestandteile.', { placeholder: '{course.elements}' }) }}
			</p>
			<div class="cert-layout-placeholders" role="group" :aria-label="t('learning', 'Platzhalter einfügen')">
				<NcButton
					v-for="placeholder in placeholders"
					:key="placeholder"
					variant="tertiary"
					size="small"
					class="cert-layout-placeholder"
					@click="insertPlaceholder(placeholder)">
					<span dir="ltr">{{ placeholder }}</span>
				</NcButton>
			</div>
		</div>

		<!-- GitHub #77: the organisation the certificate names, and whether the name stands beside the logo -->
		<fieldset class="cert-config-field cert-layout-org" data-test="cert-layout-org">
			<legend>{{ t('learning', 'Ausstellende Organisation') }}</legend>
			<!-- GitHub #78: the logo from the admin's logo library, or the Theming logo; taken over at once -->
			<div
				class="cert-config-field cert-layout-logo"
				role="radiogroup"
				:aria-labelledby="logoLabelId"
				:aria-describedby="logoHintId + ' ' + logoErrorId"
				data-test="cert-layout-logo">
				<span :id="logoLabelId" class="cert-layout-sub-label">{{ t('learning', 'Logo') }}</span>
				<NcCheckboxRadioSwitch
					type="radio"
					:name="'cert-layout-logo-' + courseId"
					value="theming"
					:model-value="selectedLogo || 'theming'"
					data-test="cert-layout-logo-theming"
					@update:model-value="chooseLogo('')">
					{{ t('learning', 'Logo dieser Nextcloud (Theming)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-for="logo in logos"
					:key="logo.id"
					type="radio"
					:name="'cert-layout-logo-' + courseId"
					:value="logo.id"
					:model-value="selectedLogo || 'theming'"
					:data-test="'cert-layout-logo-' + logo.id"
					@update:model-value="chooseLogo(logo.id)">
					<span class="cert-layout-logo-option">
						<img :src="logoImageUrl(logo.id)" alt="" class="cert-layout-logo-thumb">
						<span>{{ logo.name }}</span>
					</span>
				</NcCheckboxRadioSwitch>
				<p :id="logoHintId" class="mode-config-hint">
					{{ logos.length
						? t('learning', 'Die Logos verwaltet die Administration unter Verwaltung → Learning. Bereits ausgestellte Zertifikate behalten ihr Logo.')
						: t('learning', 'Weitere Logos (z. B. das vollständige Logo statt des Favicons) kann die Administration unter Verwaltung → Learning hinterlegen.') }}
				</p>
				<!-- always in the DOM: a live region that appears together with its text is often not read -->
				<p
					:id="logoErrorId"
					class="cert-layout-logo-error"
					aria-live="polite"
					aria-atomic="true"
					data-test="cert-layout-logo-error">
					<template v-if="logoError">
						{{ logoError }}
					</template>
				</p>
			</div>
			<div class="cert-config-field">
				<label :for="issuerNameId">{{ t('learning', 'Name der Organisation auf dem Zertifikat') }}</label>
				<input
					:id="issuerNameId"
					:value="modelValue.issuer_name"
					type="text"
					:maxlength="issuerNameMax"
					:placeholder="fallbackIssuerName"
					class="cert-layout-text"
					data-test="cert-layout-issuer-name"
					:aria-describedby="issuerNameHintId"
					@input="update({ issuer_name: $event.target.value })">
				<p :id="issuerNameHintId" class="mode-config-hint">
					{{ t('learning', 'Leer lassen für „{name}“ (rechtlicher Name aus den Betreiberangaben, sonst der Name dieser Nextcloud). Der Name wird in neue Zertifikate signiert und steht auch auf der öffentlichen Prüfseite.', { name: fallbackIssuerName }, undefined, { escape: false, sanitize: false }) }}
				</p>
			</div>
			<!-- the description is tied to the switch's input (aria-describedby) by the component -->
			<NcCheckboxRadioSwitch
				:model-value="modelValue.show?.issuer !== false"
				:description="t('learning', 'Ausgeschaltet steht nur das Logo auf dem Zertifikat, etwa wenn das Logo den Namen schon als Schriftzug enthält. Im signierten Zertifikat und auf der Prüfseite bleibt der Name.')"
				data-test="cert-layout-show-issuer"
				@update:model-value="toggle('issuer', $event)">
				{{ t('learning', 'Name der Organisation neben dem Logo anzeigen') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<!-- GitHub #93: background from the admin's library or one that ships with the app, and its
			strength; taken over at once like the logo -->
		<fieldset class="cert-config-field cert-layout-background" :aria-describedby="backgroundHintId + ' ' + backgroundErrorId" data-test="cert-layout-background">
			<legend>{{ t('learning', 'Hintergrund') }}</legend>
			<NcCheckboxRadioSwitch
				type="radio"
				:name="'cert-layout-background-' + courseId"
				value="none"
				:model-value="selectedBackground || 'none'"
				data-test="cert-layout-background-none"
				@update:model-value="chooseBackground('')">
				{{ t('learning', 'Kein Hintergrund') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-for="builtin in builtinOptions"
				:key="builtin.id"
				type="radio"
				:name="'cert-layout-background-' + courseId"
				:value="builtin.id"
				:model-value="selectedBackground || 'none'"
				:data-test="'cert-layout-background-' + builtin.key"
				@update:model-value="chooseBackground(builtin.id)">
				{{ builtin.label }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-for="background in backgrounds"
				:key="background.id"
				type="radio"
				:name="'cert-layout-background-' + courseId"
				:value="background.id"
				:model-value="selectedBackground || 'none'"
				:data-test="'cert-layout-background-' + background.id"
				@update:model-value="chooseBackground(background.id)">
				<span class="cert-layout-logo-option">
					<img :src="backgroundImageUrl(background.id)" alt="" class="cert-layout-background-thumb">
					<span>{{ background.name }}</span>
				</span>
			</NcCheckboxRadioSwitch>
			<div v-if="selectedBackground" class="cert-config-field cert-layout-intensity">
				<label :for="intensityId">{{ t('learning', 'Stärke des Hintergrunds') }}</label>
				<div class="cert-layout-intensity-row">
					<input
						:id="intensityId"
						:value="intensity"
						type="range"
						:min="intensityMin"
						max="100"
						:step="intensityStep"
						:aria-valuetext="intensity + ' %'"
						class="cert-layout-intensity-input"
						data-test="cert-layout-background-intensity"
						@input="intensity = Number($event.target.value)"
						@change="saveBackground">
					<output :for="intensityId" class="cert-layout-intensity-value">{{ intensity }} %</output>
				</div>
			</div>
			<p :id="backgroundHintId" class="mode-config-hint">
				{{ t('learning', 'Die Schrift steht immer auf einer hellen Fläche, damit sie in jeder Stärke lesbar bleibt. Bereits ausgestellte Zertifikate behalten ihren Hintergrund.') }}
				<template v-if="!backgrounds.length">
					{{ t('learning', 'Eigene Hintergründe kann die Administration unter Verwaltung → Learning hinterlegen.') }}
				</template>
			</p>
			<!-- always in the DOM: a live region that appears together with its text is often not read -->
			<p
				:id="backgroundErrorId"
				class="cert-layout-logo-error"
				aria-live="polite"
				aria-atomic="true"
				data-test="cert-layout-background-error">
				<template v-if="backgroundError">
					{{ backgroundError }}
				</template>
			</p>
		</fieldset>

		<fieldset class="cert-config-field cert-layout-switches">
			<legend>{{ t('learning', 'Elemente auf dem Zertifikat') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="item in switches"
				:key="item.key"
				:model-value="modelValue.show[item.key] !== false"
				:data-test="'cert-layout-show-' + item.key"
				@update:model-value="toggle(item.key, $event)">
				{{ item.label }}
			</NcCheckboxRadioSwitch>
			<p class="mode-config-hint">
				{{ t('learning', 'QR-Code und Prüf-Link werden immer gedruckt: Ohne sie kann niemand prüfen, ob das Zertifikat echt ist.') }}
			</p>
		</fieldset>

		<fieldset class="cert-config-field cert-layout-signoff">
			<legend>{{ t('learning', 'Stempel und Unterschrift') }}</legend>
			<p :id="imageHintId" class="mode-config-hint">
				{{ t('learning', 'PNG-Datei aus deinen Dateien, höchstens 1 MB und 3000 × 3000 Pixel. Ein transparenter Hintergrund sieht auf dem Zertifikat am besten aus.') }}
			</p>
			<!-- GitHub #72: a refused file is named right here, not only at the top of the page -->
			<ImageFileField
				v-for="kind in imageKinds"
				:key="kind.key"
				:id-base="'cert-layout-' + kind.key + '-' + courseId"
				:label="kind.label"
				:image-url="images[kind.key]"
				:described-by="imageHintId"
				:error="imageErrors[kind.key]"
				:busy="imageBusy !== ''"
				:pick-label="t('learning', 'PNG aus Dateien wählen')"
				:pick-aria-label="t('learning', '{kind}: PNG aus Dateien wählen', { kind: kind.label })"
				:remove-aria-label="t('learning', '„{title}“ entfernen', { title: kind.label })"
				:test-id="'cert-layout-image-' + kind.key"
				:pick-test-id="'cert-layout-pick-' + kind.key"
				:remove-test-id="'cert-layout-remove-' + kind.key"
				@pick="pickImage(kind.key)"
				@remove="removeImage(kind.key)" />
			<div class="cert-config-field" data-test="cert-layout-signer-name-field">
				<label :for="signerNameId">{{ t('learning', 'Name der unterschreibenden Person') }}</label>
				<input
					:id="signerNameId"
					:value="modelValue.signer_name"
					type="text"
					:maxlength="signerMax"
					class="cert-layout-text"
					data-test="cert-layout-signer-name"
					@input="update({ signer_name: $event.target.value })">
			</div>
			<div class="cert-config-field" data-test="cert-layout-signer-role-field">
				<label :for="signerRoleId">{{ t('learning', 'Funktion (z. B. Leitung Weiterbildung)') }}</label>
				<input
					:id="signerRoleId"
					:value="modelValue.signer_role"
					type="text"
					:maxlength="signerMax"
					class="cert-layout-text"
					data-test="cert-layout-signer-role"
					@input="update({ signer_role: $event.target.value })">
			</div>
		</fieldset>

		<!-- GitHub #93: open by itself once a background is chosen, so its effect is seen at once -->
		<details
			class="cert-layout-preview"
			:open="previewOpen"
			data-test="cert-layout-preview"
			@toggle="previewOpen = $event.target.open">
			<summary>{{ t('learning', 'Vorschau (Beispielwerte)') }}</summary>
			<CertificateCard
				:fields="previewFields"
				:presentation="previewPresentation"
				verify-url="https://…/apps/learning/verify/…"
				verification-id="00000000-0000-4000-8000-000000000000"
				:issued-date="previewIssued"
				:expiry-date="previewExpiry" />
		</details>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import CertificateCard from './CertificateCard.vue'
import ImageFileField from './ImageFileField.vue'
import { BACKGROUND_BUILTINS, BACKGROUND_INTENSITY_DEFAULT, BACKGROUND_INTENSITY_MIN, BACKGROUND_INTENSITY_STEP, PLACEHOLDERS, isValidIntensity } from '../utils/certificateLayout.js'
import {
	certBackgroundImageUrl,
	certLogoImageUrl,
	getCertBackgrounds,
	getCertLayout,
	getCertLogos,
	importCertLayoutImage,
	removeCertLayoutImage,
	selectCertBackground,
	selectCertLogo,
} from '../services/CertificateService.js'
import { addValidity } from '../utils/certValidity.js'

const INTRO_MAX = 2000
const SIGNER_MAX = 120
/** CertLayout::ISSUER_NAME_MAX (GitHub #77) */
const ISSUER_NAME_MAX = 200
/** CertLayoutService::MAX_BYTES: a larger file is refused before it is sent (GitHub #72) */
export const IMAGE_MAX_BYTES = 1048576

/**
 * The message for a refused image (CertLayoutController error codes).
 * @param {object} error - axios error
 * @return {string}
 */
export function imageErrorMessage(error) {
	const code = error?.response?.data?.error
	if (code === 'not_png') {
		return t('learning', 'Die Datei ist kein gültiges PNG-Bild.')
	}
	if (code === 'too_large') {
		return t('learning', 'Die Datei ist zu groß (höchstens 1 MB und 3000 × 3000 Pixel).')
	}
	if (code === 'not_downloadable') {
		return t('learning', 'Die Datei ist nur zum Ansehen freigegeben (Herunterladen gesperrt) und kann deshalb nicht übernommen werden.')
	}
	if (code === 'file_not_found') {
		return t('learning', 'Die Datei wurde nicht gefunden.')
	}
	return t('learning', 'Das Bild konnte nicht übernommen werden.')
}

/**
 * GitHub #93: the background a course layout names, as the value of the choice.
 * @param {object} layout - the course's cert_layout (CertLayout::forSettings)
 * @return {string} '' = none, 'builtin:<id>' or a library background's id
 */
export function backgroundChoice(layout) {
	if (typeof layout?.background_id === 'string' && /^[0-9a-f]{64}$/.test(layout.background_id)) {
		return layout.background_id
	}
	return BACKGROUND_BUILTINS.includes(layout?.background_builtin) ? 'builtin:' + layout.background_builtin : ''
}

export default {
	name: 'CertLayoutSettings',

	components: {
		CertificateCard,
		ImageFileField,
		NcButton,
		NcCheckboxRadioSwitch,
	},

	props: {
		courseId: { type: Number, required: true },
		/** { intro, show, signer_name, signer_role } as saved with the certification settings */
		modelValue: { type: Object, required: true },
		courseTitle: { type: String, default: '' },
		requiredPoolNames: { type: Array, default: () => [] },
		passPercent: { type: Number, default: 80 },
		// GitHub #75: {amount, unit} with unit days, months or years (utils/certValidity.js)
		validity: { type: Object, default: () => ({ amount: 0, unit: 'days' }) },
	},

	emits: ['update:modelValue'],

	data() {
		const initialBackground = backgroundChoice(this.modelValue)
		const initialIntensity = isValidIntensity(this.modelValue?.background_intensity) ? this.modelValue.background_intensity : BACKGROUND_INTENSITY_DEFAULT
		return {
			images: { stamp: '', signature: '', logo: '', background: '' },
			// GitHub #93: '' = none, 'builtin:<id>' or a library background's id
			backgrounds: [],
			selectedBackground: initialBackground,
			savedBackground: initialBackground,
			intensity: initialIntensity,
			savedIntensity: initialIntensity,
			backgroundQueued: false,
			backgroundBusy: false,
			backgroundError: '',
			previewOpen: initialBackground !== '',
			intensityMin: BACKGROUND_INTENSITY_MIN,
			intensityStep: BACKGROUND_INTENSITY_STEP,
			intensityId: `cert-layout-background-intensity-${this.courseId}`,
			backgroundHintId: `cert-layout-background-hint-${this.courseId}`,
			backgroundErrorId: `cert-layout-background-error-${this.courseId}`,
			// GitHub #78: the library logos this course can use; '' = the Theming logo
			logos: [],
			selectedLogo: typeof this.modelValue?.logo_id === 'string' ? this.modelValue.logo_id : '',
			// the stored choice; selectedLogo runs ahead of it while a choice is saved
			savedLogo: typeof this.modelValue?.logo_id === 'string' ? this.modelValue.logo_id : '',
			pendingLogo: null,
			logoBusy: false,
			logoError: '',
			logoLabelId: `cert-layout-logo-label-${this.courseId}`,
			logoHintId: `cert-layout-logo-hint-${this.courseId}`,
			logoErrorId: `cert-layout-logo-error-${this.courseId}`,
			imageErrors: { stamp: '', signature: '' },
			imageBusy: '',
			imageSeq: 0,
			introMax: INTRO_MAX,
			signerMax: SIGNER_MAX,
			issuerNameMax: ISSUER_NAME_MAX,
			// GitHub #77: what an empty organisation name gives (operator details, then Theming); from the server
			issuerNameFallback: '',
			issuerNameId: `cert-layout-issuer-name-${this.courseId}`,
			issuerNameHintId: `cert-layout-issuer-name-hint-${this.courseId}`,
			placeholders: PLACEHOLDERS,
			introId: `cert-layout-intro-${this.courseId}`,
			introHintId: `cert-layout-intro-hint-${this.courseId}`,
			signerNameId: `cert-layout-signer-name-${this.courseId}`,
			signerRoleId: `cert-layout-signer-role-${this.courseId}`,
			imageHintId: `cert-layout-image-hint-${this.courseId}`,
		}
	},

	computed: {
		switches() {
			return [
				{ key: 'logo', label: t('learning', 'Logo') },
				{ key: 'score', label: t('learning', 'Ergebnis') },
				{ key: 'threshold', label: t('learning', 'Mindestscore') },
				{ key: 'elements', label: t('learning', 'Kursbestandteile') },
				{ key: 'validity', label: t('learning', 'Gültig bis') },
			]
		},
		imageKinds() {
			return [
				{ key: 'stamp', label: t('learning', 'Stempel') },
				{ key: 'signature', label: t('learning', 'Unterschrift') },
			]
		},
		builtinOptions() {
			const labels = {
				frame: t('learning', 'Doppelrahmen'),
				corners: t('learning', 'Eckornamente'),
				lines: t('learning', 'Feines Linienmuster'),
			}
			return BACKGROUND_BUILTINS.map((key) => ({ key, id: 'builtin:' + key, label: labels[key] }))
		},
		fallbackIssuerName() {
			return this.issuerNameFallback || (typeof window !== 'undefined' && window.OC?.theme?.name) || 'Nextcloud'
		},
		previewFields() {
			return {
				courseTitle: this.courseTitle,
				recipientName: t('learning', 'Alex Beispiel'),
				courseElements: [...this.requiredPoolNames],
				score: 92,
				threshold: this.passPercent,
				// GitHub #77: the name the next certificate is issued in
				issuerName: (this.modelValue.issuer_name || '').trim() || this.fallbackIssuerName,
				// sample only: the real card shows the instance's themed logo
				issuerLogo: `${(typeof window !== 'undefined' && window.OC?.webroot) || ''}/core/img/logo/logo.svg`,
				validFrom: '',
				validUntil: '',
			}
		},
		previewPresentation() {
			return {
				intro: this.modelValue.intro || '',
				show: this.modelValue.show || {},
				signer_name: this.modelValue.signer_name || '',
				signer_role: this.modelValue.signer_role || '',
				stamp: this.images.stamp || null,
				signature: this.images.signature || null,
				logo: this.images.logo || null,
				// GitHub #93: the choice and strength as they are on screen, the library image once stored
				background: this.selectedBackground && !this.selectedBackground.startsWith('builtin:') ? (this.images.background || null) : null,
				background_builtin: this.selectedBackground.startsWith('builtin:') ? this.selectedBackground.slice(8) : null,
				background_intensity: this.intensity,
			}
		},
		previewIssued() {
			return this.formatDate(new Date())
		},
		previewExpiry() {
			const date = addValidity(new Date(), this.validity)
			return date ? this.formatDate(date) : ''
		},
	},

	mounted() {
		this.loadImages()
		this.loadLogos()
		this.loadBackgrounds()
	},

	methods: {
		update(change) {
			this.$emit('update:modelValue', { ...this.modelValue, ...change })
		},

		toggle(key, value) {
			this.update({ show: { ...(this.modelValue.show || {}), [key]: !!value } })
		},

		insertPlaceholder(placeholder) {
			const area = this.$refs.intro
			const text = this.modelValue.intro || ''
			const start = area && typeof area.selectionStart === 'number' ? area.selectionStart : text.length
			const end = area && typeof area.selectionEnd === 'number' ? area.selectionEnd : text.length
			const next = (text.slice(0, start) + placeholder + text.slice(end)).slice(0, INTRO_MAX)
			this.update({ intro: next })
			this.$nextTick(() => {
				if (area) {
					area.focus()
					const caret = Math.min(start + placeholder.length, next.length)
					area.setSelectionRange?.(caret, caret)
				}
			})
		},

		applyImages(layout) {
			if (typeof layout?.issuer_name_fallback === 'string') {
				this.issuerNameFallback = layout.issuer_name_fallback
			}
			this.images = {
				stamp: typeof layout?.stamp === 'string' ? layout.stamp : '',
				signature: typeof layout?.signature === 'string' ? layout.signature : '',
				logo: typeof layout?.logo === 'string' ? layout.logo : '',
				background: typeof layout?.background === 'string' ? layout.background : '',
			}
		},

		/**
		 * The stored images, whatever the course prop says: an image picked or removed without
		 * saving the settings is stored at once, but the course the settings were mounted from
		 * still has the old has_stamp/has_signature until the next course reload (GitHub #43).
		 */
		async loadImages() {
			const seq = this.imageSeq
			try {
				const layout = await getCertLayout(this.courseId)
				// a pick or remove started meanwhile: its answer is newer than this one
				if (seq === this.imageSeq) {
					this.applyImages(layout)
				}
			} catch (e) {
				// the preview stays without images; saving is not affected
			}
		},

		/** Answer of an import/remove: show it and tell the parent what is stored now. */
		imagesChanged(layout) {
			this.applyImages(layout)
			this.update({ has_stamp: !!this.images.stamp, has_signature: !!this.images.signature })
		},

		logoImageUrl(id) {
			return certLogoImageUrl(this.courseId, id)
		},

		/** GitHub #78: the library and the course's choice. Without it only the Theming logo is offered. */
		async loadLogos() {
			try {
				const data = await getCertLogos(this.courseId)
				this.logos = Array.isArray(data?.logos) ? data.logos.filter((logo) => typeof logo?.id === 'string') : []
				if (!this.logoBusy) {
					this.selectedLogo = typeof data?.selected === 'string' ? data.selected : ''
					this.savedLogo = this.selectedLogo
				}
			} catch (e) {
				this.logos = []
			}
		},

		/**
		 * GitHub #78: takes effect at once, like stamp and signature.
		 * The radios stay enabled while a choice is saved (review: a disabled radio drops the
		 * keyboard focus to the page, so the next arrow key did nothing). A choice made meanwhile
		 * is shown at once and saved after the running one: the last choice wins.
		 */
		async chooseLogo(id) {
			this.selectedLogo = id
			this.pendingLogo = id
			if (this.logoBusy) {
				return
			}
			this.logoBusy = true
			this.logoError = ''
			try {
				while (this.pendingLogo !== null) {
					const next = this.pendingLogo
					this.pendingLogo = null
					if (next === this.savedLogo) {
						continue
					}
					this.imageSeq++
					try {
						const layout = await selectCertLogo(this.courseId, next)
						this.savedLogo = next
						this.applyImages(layout)
						this.update({ logo_id: next || null })
					} catch (e) {
						this.pendingLogo = null
						this.selectedLogo = this.savedLogo
						this.logoError = e?.response?.data?.error === 'logo_not_found'
							? t('learning', 'Dieses Logo gibt es in der Bibliothek nicht mehr.')
							: t('learning', 'Das Logo konnte nicht übernommen werden.')
						this.loadLogos()
						this.loadImages()
					}
				}
			} finally {
				this.logoBusy = false
			}
		},

		backgroundImageUrl(id) {
			return certBackgroundImageUrl(this.courseId, id)
		},

		/** GitHub #93: the library, the built-in ids and the course's choice. Without it the built-in ones remain. */
		async loadBackgrounds() {
			try {
				const data = await getCertBackgrounds(this.courseId)
				this.backgrounds = Array.isArray(data?.backgrounds) ? data.backgrounds.filter((entry) => typeof entry?.id === 'string') : []
				if (!this.backgroundBusy) {
					this.selectedBackground = typeof data?.selected === 'string' ? data.selected : ''
					this.savedBackground = this.selectedBackground
					this.intensity = isValidIntensity(data?.intensity) ? data.intensity : BACKGROUND_INTENSITY_DEFAULT
					this.savedIntensity = this.intensity
				}
			} catch (e) {
				this.backgrounds = []
			}
		},

		chooseBackground(id) {
			this.selectedBackground = id
			if (id) {
				this.previewOpen = true
			}
			this.saveBackground()
		},

		/**
		 * GitHub #93: takes effect at once, like the logo. The slider changes the preview while it
		 * moves and saves when it is let go; whatever is chosen while a save runs is saved after it
		 * (the last choice wins), so dragging never queues a request per step.
		 */
		async saveBackground() {
			this.backgroundQueued = true
			if (this.backgroundBusy) {
				return
			}
			this.backgroundBusy = true
			this.backgroundError = ''
			try {
				while (this.backgroundQueued) {
					this.backgroundQueued = false
					const choice = this.selectedBackground
					const intensity = this.intensity
					if (choice === this.savedBackground && (choice === '' || intensity === this.savedIntensity)) {
						continue
					}
					this.imageSeq++
					try {
						const layout = await selectCertBackground(this.courseId, choice, intensity)
						this.savedBackground = choice
						this.savedIntensity = choice === '' ? BACKGROUND_INTENSITY_DEFAULT : intensity
						this.applyImages(layout)
						this.update({
							background_id: choice && !choice.startsWith('builtin:') ? choice : null,
							background_builtin: choice.startsWith('builtin:') ? choice.slice(8) : null,
							background_intensity: this.savedIntensity,
						})
					} catch (e) {
						this.backgroundQueued = false
						this.selectedBackground = this.savedBackground
						this.intensity = this.savedIntensity
						this.backgroundError = e?.response?.data?.error === 'background_not_found'
							? t('learning', 'Diesen Hintergrund gibt es in der Bibliothek nicht mehr.')
							: t('learning', 'Der Hintergrund konnte nicht übernommen werden.')
						this.loadBackgrounds()
						this.loadImages()
					}
				}
			} finally {
				this.backgroundBusy = false
			}
		},

		setImageError(kind, message) {
			this.imageErrors = { ...this.imageErrors, [kind]: message }
		},

		async pickImage(kind) {
			let chosen = null
			try {
				// Loaded on use: the settings tab (and every test mounting it) does not need the dialogs.
				const { getFilePickerBuilder } = await import('@nextcloud/dialogs')
				const picker = getFilePickerBuilder(kind === 'stamp'
					? t('learning', 'Stempel auswählen')
					: t('learning', 'Unterschrift auswählen'))
					.setMultiSelect(false)
					.setMimeTypeFilter(['image/png'])
					.setButtonFactory((selected) => [{
						label: t('learning', 'Diese Datei übernehmen'),
						variant: 'primary',
						disabled: !selected[0]?.path,
						callback: (nodes) => {
							chosen = nodes[0]?.path ? nodes[0] : null
						},
					}])
					.build()
				await picker.pickNodes()
			} catch (e) {
				// closed without choosing: an earlier message still describes the stored state
				return
			}
			if (!chosen) {
				return
			}
			// a new attempt: the message about the previous file no longer applies
			this.setImageError(kind, '')
			// The picker knows the size: a file over the limit is named at once, without a
			// request. Pixels are only known to the server, which refuses them as too_large.
			if (typeof chosen.size === 'number' && chosen.size > IMAGE_MAX_BYTES) {
				// Let the cleared region reach the DOM first: the same refusal twice in a row would
				// otherwise leave the live region's text unchanged, and nothing would be announced.
				await this.$nextTick()
				this.setImageError(kind, imageErrorMessage({ response: { data: { error: 'too_large' } } }))
				return
			}
			this.imageBusy = kind
			this.imageSeq++
			try {
				this.imagesChanged(await importCertLayoutImage(this.courseId, kind, chosen.path))
			} catch (e) {
				this.setImageError(kind, imageErrorMessage(e))
				// the load this call overtook is void: fetch what is stored now
				this.loadImages()
			} finally {
				this.imageBusy = ''
			}
		},

		async removeImage(kind) {
			this.imageBusy = kind
			this.imageSeq++
			this.setImageError(kind, '')
			try {
				this.imagesChanged(await removeCertLayoutImage(this.courseId, kind))
			} catch (e) {
				this.setImageError(kind, t('learning', 'Das Bild konnte nicht entfernt werden.'))
				this.loadImages()
			} finally {
				this.imageBusy = ''
			}
		},

		formatDate(date) {
			return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
		},
	},
}
</script>

<style scoped>
.cert-layout {
	margin: 18px 0;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.cert-layout h4 {
	margin: 0 0 6px;
}

/* GitHub #73: the field layout of the other course rules. CourseTabVerwaltung's rule for
   .cert-config-field is scoped and does not reach into this component, so the labels stood
   beside their inputs: label on top, input full width below. */
.cert-config-field {
	margin: 14px 0;
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: 6px;
}

.cert-config-field > label {
	font-weight: 600;
	font-size: 0.9em;
}

/* the hint style of the other course rules; the parent's scoped rule does not reach here either */
.mode-config-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 8px;
}

.cert-layout-intro {
	width: 100%;
	max-width: 640px;
	min-height: 110px;
	box-sizing: border-box;
	resize: vertical;
}

.cert-layout-placeholders {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.cert-layout-org,
.cert-layout-switches,
.cert-layout-signoff {
	border: none;
	padding: 0;
	margin-inline: 0;
}

.cert-layout-org legend,
.cert-layout-switches legend,
.cert-layout-signoff legend {
	font-weight: 600;
	font-size: 0.9em;
	padding: 0;
	margin-bottom: 6px;
}

.cert-layout-sub-label {
	font-weight: 600;
	font-size: 0.9em;
}

.cert-layout-logo-option {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

/* the logo is printed on white paper: show it on white in dark mode too */
.cert-layout-logo-thumb {
	max-height: 32px;
	max-width: 120px;
	object-fit: contain;
	background: #fff;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: 2px;
}

.cert-layout-background {
	border: none;
	padding: 0;
	margin-inline: 0;
}

.cert-layout-background legend {
	font-weight: 600;
	font-size: 0.9em;
	padding: 0;
	margin-bottom: 6px;
}

.cert-layout-background-thumb {
	width: 60px;
	height: 40px;
	object-fit: cover;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: #fff;
}

.cert-layout-intensity-row {
	display: flex;
	align-items: center;
	gap: 12px;
	max-width: 420px;
}

.cert-layout-intensity-input {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
}

.cert-layout-intensity-value {
	min-width: 3.5em;
	font-variant-numeric: tabular-nums;
}

.cert-layout-logo-error {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.cert-layout-text {
	width: 100%;
	max-width: 640px;
	margin-inline: 0;
	box-sizing: border-box;
}

.cert-layout-preview {
	margin-top: 12px;
	max-width: 640px;
}

.cert-layout-preview summary {
	cursor: pointer;
	font-weight: 600;
	margin-bottom: 8px;
}
</style>
