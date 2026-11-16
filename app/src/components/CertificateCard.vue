<template>
	<!-- GitHub #43: the card alone, drawn from the signed fields and the certificate's layout.
		Certificate.vue (learner, instructor copy, print) and the course settings preview share it,
		so the preview is exactly what will be printed. Text goes into text nodes only. -->
	<div class="certificate-card">
		<div class="certificate-brand">
			<!-- GitHub #77: with the name hidden (a wordmark logo says it already) the logo is what names
				the issuer, so it carries the name as its text alternative; beside the name it is decoration. -->
			<img
				v-if="layout.show.logo && logoSrc"
				:src="logoSrc"
				:alt="layout.show.issuer ? '' : fields.issuerName"
				:aria-hidden="layout.show.issuer ? 'true' : null"
				class="certificate-logo"
				data-test="certificate-logo">
			<span v-if="showIssuerName" class="certificate-issuer" data-test="certificate-issuer">{{ fields.issuerName }}</span>
		</div>

		<p class="certificate-kicker">{{ t('learning', 'Zertifikat') }}</p>
		<h2 class="certificate-title">{{ fields.courseTitle }}</h2>

		<div v-if="introBlocks.length" class="certificate-intro" data-test="certificate-intro">
			<template v-for="(block, index) in introBlocks" :key="index">
				<p v-if="block.type === 'text'" class="certificate-intro-line">
					<template v-for="(part, partIndex) in block.parts" :key="partIndex">
						<strong v-if="part.strong">{{ part.text }}</strong>
						<template v-else>{{ part.text }}</template>
					</template>
				</p>
				<ul v-else-if="block.type === 'list'" class="certificate-elements-list">
					<li v-for="(item, itemIndex) in block.items" :key="itemIndex">
						{{ item }}
					</li>
				</ul>
				<div v-else class="certificate-intro-gap" aria-hidden="true" />
			</template>
		</div>
		<p v-else-if="fields.recipientName" class="certificate-recipient">
			{{ t('learning', 'Verliehen an') }}: <strong>{{ fields.recipientName }}</strong>
		</p>

		<!-- GitHub #43: the required pools signed into the certificate; none on older ones or "any exam" courses. -->
		<section v-if="showElementsSection" class="certificate-elements">
			<h3 class="certificate-meta-label">
				{{ t('learning', 'Kursbestandteile') }}
			</h3>
			<ul class="certificate-elements-list">
				<li v-for="(element, index) in fields.courseElements" :key="index">
					{{ element }}
				</li>
			</ul>
		</section>

		<div class="certificate-meta">
			<div v-if="layout.show.score && fields.score !== null" class="certificate-meta-item">
				<span class="certificate-meta-label">{{ t('learning', 'Ergebnis') }}</span>
				<span class="certificate-meta-value">{{ fields.score }}%</span>
			</div>
			<div v-if="layout.show.threshold && fields.threshold !== null" class="certificate-meta-item">
				<span class="certificate-meta-label">{{ t('learning', 'Mindestscore') }}</span>
				<span class="certificate-meta-value">{{ fields.threshold }}%</span>
			</div>
			<div v-if="issuedDate" class="certificate-meta-item">
				<span class="certificate-meta-label">{{ t('learning', 'Ausgestellt am') }}</span>
				<span class="certificate-meta-value">{{ issuedDate }}</span>
			</div>
			<div v-if="layout.show.validity && expiryDate" class="certificate-meta-item">
				<span class="certificate-meta-label">{{ t('learning', 'Gültig bis') }}</span>
				<span class="certificate-meta-value">{{ expiryDate }}</span>
			</div>
		</div>

		<div v-if="hasSignatureBlock" class="certificate-signoff" data-test="certificate-signoff">
			<div class="certificate-signoff-images">
				<img
					v-if="layout.stamp"
					:src="layout.stamp"
					:alt="t('learning', 'Stempel')"
					class="certificate-stamp">
				<img
					v-if="layout.signature"
					:src="layout.signature"
					:alt="t('learning', 'Unterschrift')"
					class="certificate-signature">
			</div>
			<div v-if="layout.signerName || layout.signerRole" class="certificate-signer">
				<span v-if="layout.signerName" class="certificate-signer-name">{{ layout.signerName }}</span>
				<span v-if="layout.signerRole" class="certificate-signer-role">{{ layout.signerRole }}</span>
			</div>
		</div>

		<!-- QR code and verification link are always printed: without them nobody can check the paper. -->
		<div class="certificate-verify">
			<img
				v-if="qrDataUrl"
				:src="qrDataUrl"
				:alt="t('learning', 'QR-Code zur Verifizierung')"
				class="certificate-qr">
			<div class="certificate-verify-copy">
				<span class="certificate-meta-label">{{ t('learning', 'Scannen oder ID prüfen unter') }}</span>
				<span class="certificate-verify-url">{{ verifyUrl }}</span>
				<span class="certificate-verify-id-label">{{ t('learning', 'Zertifikat-ID') }}</span>
				<span class="certificate-verify-id">{{ verificationId }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { normalizePresentation, placeholderValues, renderIntro } from '../utils/certificateLayout.js'

export default {
	name: 'CertificateCard',

	props: {
		/** extractCertificateFields() result (signed, frozen values) */
		fields: {
			type: Object,
			required: true,
		},
		/** the certificate's layout from the show response; null = the standard card */
		presentation: {
			type: Object,
			default: null,
		},
		verifyUrl: {
			type: String,
			default: '',
		},
		verificationId: {
			type: String,
			default: '',
		},
		qrDataUrl: {
			type: String,
			default: '',
		},
		issuedDate: {
			type: String,
			default: '',
		},
		expiryDate: {
			type: String,
			default: '',
		},
	},

	computed: {
		layout() {
			return normalizePresentation(this.presentation)
		},
		introBlocks() {
			return renderIntro(this.layout.intro, placeholderValues(this.fields, {
				issuedDate: this.issuedDate,
				expiryDate: this.expiryDate,
				noExpiry: t('learning', 'unbefristet'),
			}))
		},
		showElementsSection() {
			const elements = this.fields.courseElements
			return this.layout.show.elements
				&& Array.isArray(elements) && elements.length > 0
				// the text already lists them
				&& !this.layout.intro.includes('{course.elements}')
		},
		/**
		 * GitHub #77: the switch hides the name beside the logo. Without a logo on the card the name
		 * stays: a certificate that names no issuer at all is not what the switch is for.
		 */
		showIssuerName() {
			return this.layout.show.issuer || !(this.layout.show.logo && this.logoSrc)
		},
		/** GitHub #78: the library logo the certificate was issued with, else the Theming logo of the credential */
		logoSrc() {
			return this.layout.logo || this.fields.issuerLogo
		},
		hasSignatureBlock() {
			return !!(this.layout.stamp || this.layout.signature || this.layout.signerName || this.layout.signerRole)
		},
	},
}
</script>

<style scoped>
.certificate-card {
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius-large, 12px);
	padding: 32px;
	background: var(--color-main-background);
	text-align: center;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.certificate-brand {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
}

.certificate-logo {
	max-height: 48px;
	max-width: 160px;
	object-fit: contain;
}

.certificate-issuer {
	font-weight: 700;
	color: var(--color-main-text);
}

.certificate-kicker {
	margin: 0;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 2px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.certificate-title {
	margin: 0;
	font-size: 1.8em;
	color: var(--color-main-text);
}

.certificate-recipient {
	margin: 0;
	color: var(--color-main-text);
}

.certificate-intro {
	display: flex;
	flex-direction: column;
	gap: 4px;
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.certificate-intro-line {
	margin: 0;
}

.certificate-intro-gap {
	height: 0.6em;
}

.certificate-elements {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
}

.certificate-elements h3 {
	margin: 0;
}

.certificate-elements-list {
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-main-text);
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.certificate-meta {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 24px;
	margin-top: 8px;
}

.certificate-meta-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.certificate-meta-label {
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 1px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.certificate-meta-value {
	font-size: 1.2em;
	font-weight: 700;
	color: var(--color-main-text);
}

.certificate-signoff {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	margin-top: 8px;
}

.certificate-signoff-images {
	display: flex;
	align-items: flex-end;
	justify-content: center;
	gap: 24px;
}

/* Stamps and signatures are usually dark ink on a transparent background: on the dark-mode card
   they would vanish, so they sit on white like on paper (invisible on the light card). */
.certificate-stamp,
.certificate-signature {
	background: #fff;
	border-radius: var(--border-radius, 4px);
	padding: 4px;
}

.certificate-stamp {
	max-height: 96px;
	max-width: 140px;
	object-fit: contain;
}

.certificate-signature {
	max-height: 64px;
	max-width: 220px;
	object-fit: contain;
}

.certificate-signer {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 200px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border-dark, var(--color-border));
	color: var(--color-main-text);
}

.certificate-signer-name {
	font-weight: 600;
}

.certificate-signer-role {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.certificate-verify {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 12px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.certificate-qr {
	width: 120px;
	height: 120px;
	image-rendering: pixelated;
}

.certificate-verify-copy {
	display: flex;
	flex-direction: column;
	gap: 4px;
	text-align: start;
}

.certificate-verify-url {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.certificate-verify-id-label {
	margin-top: 6px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.certificate-verify-id {
	font-family: monospace;
	font-size: 0.95em;
	font-weight: 600;
	letter-spacing: 0.5px;
	word-break: break-all;
}
</style>
