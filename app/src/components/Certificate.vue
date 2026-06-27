<template>
	<div class="certificate-view">
		<div v-if="loading" class="certificate-loading">
			<NcLoadingIcon :size="44" />
			<p>{{ t('learning', 'Lade Zertifikat...') }}</p>
		</div>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="fields">
			<!-- Printable card: signed (frozen) fields render as-is; only chrome is localized. -->
			<div class="certificate-printable">
				<div class="certificate-card">
					<div class="certificate-brand">
						<img
							v-if="fields.issuerLogo"
							:src="fields.issuerLogo"
							:alt="fields.issuerName"
							class="certificate-logo">
						<span class="certificate-issuer">{{ fields.issuerName }}</span>
					</div>

					<p class="certificate-kicker">{{ t('learning', 'Zertifikat') }}</p>
					<h2 class="certificate-title">{{ fields.courseTitle }}</h2>

					<p v-if="fields.recipientName" class="certificate-recipient">
						{{ t('learning', 'Verliehen an') }}: <strong>{{ fields.recipientName }}</strong>
					</p>

					<div class="certificate-meta">
						<div v-if="fields.score !== null" class="certificate-meta-item">
							<span class="certificate-meta-label">{{ t('learning', 'Ergebnis') }}</span>
							<span class="certificate-meta-value">{{ fields.score }}%</span>
						</div>
						<div v-if="fields.threshold !== null" class="certificate-meta-item">
							<span class="certificate-meta-label">{{ t('learning', 'Mindest-Score (%)') }}</span>
							<span class="certificate-meta-value">{{ fields.threshold }}%</span>
						</div>
						<div v-if="issuedDate" class="certificate-meta-item">
							<span class="certificate-meta-label">{{ t('learning', 'Ausgestellt am') }}</span>
							<span class="certificate-meta-value">{{ issuedDate }}</span>
						</div>
						<div v-if="expiryDate" class="certificate-meta-item">
							<span class="certificate-meta-label">{{ t('learning', 'Gültig bis') }}</span>
							<span class="certificate-meta-value">{{ expiryDate }}</span>
						</div>
					</div>

					<div class="certificate-verify">
						<img
							v-if="qrDataUrl"
							:src="qrDataUrl"
							:alt="t('learning', 'QR-Code zur Verifizierung')"
							class="certificate-qr">
						<div class="certificate-verify-copy">
							<span class="certificate-meta-label">{{ t('learning', 'Verifizieren') }}</span>
							<span class="certificate-verify-url">{{ verifyUrl }}</span>
						</div>
					</div>
				</div>
			</div>

			<div class="certificate-actions no-print">
				<NcButton type="secondary" @click="print">
					{{ t('learning', 'Drucken') }}
				</NcButton>
				<a
					:href="downloadHref"
					class="certificate-download-link"
					download>
					<NcButton type="secondary" @click.prevent="download">
						{{ t('learning', 'Herunterladen') }}
					</NcButton>
				</a>
				<NcButton type="primary" @click="openLinkedIn">
					{{ t('learning', 'Zu LinkedIn-Profil hinzufügen') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { getCertificate, downloadUrl } from '../services/CertificateService.js'
import {
	decodeCredential,
	extractCertificateFields,
	buildVerifyUrl,
	buildLinkedInUrl,
} from '../utils/certificate-credential.js'
import qrcode from '../utils/qrcode-generator.js'

export default {
	name: 'Certificate',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		verificationId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			fields: null,
			qrDataUrl: '',
		}
	},

	computed: {
		verifyUrl() {
			const origin = (typeof window !== 'undefined' && window.location)
				? window.location.origin
				: ''
			// generateUrl handles webroot + index.php so the URL is correct on every
			// install (the public verify route ships in Phase 157; its shape is frozen).
			return buildVerifyUrl(origin, generateUrl('/apps/learning/verify/' + encodeURIComponent(this.verificationId)))
		},
		downloadHref() {
			return downloadUrl(this.verificationId)
		},
		issuedDate() {
			return this.formatDate(this.fields?.validFrom)
		},
		expiryDate() {
			return this.formatDate(this.fields?.validUntil)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		async load() {
			this.loading = true
			this.error = ''
			try {
				const row = await getCertificate(this.verificationId)
				const credential = decodeCredential(row.credential_json)
				if (!credential) {
					this.error = t('learning', 'Zertifikat konnte nicht gelesen werden.')
					return
				}
				this.fields = extractCertificateFields(credential)
				this.renderQr()
			} catch (e) {
				this.error = e?.response?.data?.error
					|| t('learning', 'Zertifikat konnte nicht geladen werden.')
			} finally {
				this.loading = false
			}
		},

		renderQr() {
			try {
				const qr = qrcode(0, 'M')
				qr.addData(this.verifyUrl, 'Byte')
				qr.make()
				this.qrDataUrl = qr.createDataURL(4, 2)
			} catch (e) {
				this.qrDataUrl = ''
			}
		},

		print() {
			window.print()
		},

		download() {
			window.location.href = this.downloadHref
		},

		openLinkedIn() {
			const url = buildLinkedInUrl({
				courseTitle: this.fields.courseTitle,
				issuerName: this.fields.issuerName,
				verificationId: this.verificationId,
				certUrl: this.verifyUrl,
				issueDate: this.fields.validFrom,
			})
			window.open(url, '_blank', 'noopener,noreferrer')
		},

		formatDate(iso) {
			if (!iso) {
				return ''
			}
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			return date.toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.certificate-view {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.certificate-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 40px 16px;
	color: var(--color-text-maxcontrast);
}

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
	text-align: left;
}

.certificate-verify-url {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.certificate-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	justify-content: center;
}

.certificate-download-link {
	text-decoration: none;
}
</style>

<style>
/* Print stylesheet (CERT-07): isolate the certificate card so window.print() yields a
   clean certificate with no app chrome. The visibility trick works regardless of the
   modal's DOM nesting (NcModal teleports to <body>). */
@media print {
	body * {
		visibility: hidden;
	}

	.certificate-printable,
	.certificate-printable * {
		visibility: visible;
	}

	.certificate-printable {
		position: absolute;
		left: 0;
		top: 0;
		width: 100%;
	}

	.no-print {
		display: none !important;
	}
}
</style>
