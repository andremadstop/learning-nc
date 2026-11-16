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
			<div ref="printable" class="certificate-printable">
				<!-- GitHub #42: the instructor's copy says when it no longer counts, on screen and on paper. -->
				<p v-if="statusNote" class="certificate-status-note" role="status">
					{{ statusNote }}
				</p>
				<CertificateCard
					:fields="fields"
					:presentation="presentation"
					:verify-url="verifyUrl"
					:verification-id="verificationId"
					:qr-data-url="qrDataUrl"
					:issued-date="issuedDate"
					:expiry-date="expiryDate" />
			</div>

			<div class="certificate-actions no-print">
				<NcButton variant="primary" @click="print">
					{{ t('learning', 'Drucken') }}
				</NcButton>
				<a
					:href="downloadHref"
					class="certificate-download-link"
					download>
					<NcButton variant="primary" @click.prevent="download">
						<!-- GitHub #42: the label says what the file is. Fresh-eyes C3 (#35): the learner's copy too,
							a plain "Download" made learners expect a PDF. -->
						{{ t('learning', 'Herunterladen (JSON)') }}
					</NcButton>
				</a>
				<NcButton v-if="courseId === null && linkedinAllowed" variant="secondary" @click="openLinkedIn">
					{{ t('learning', 'Zu LinkedIn hinzufügen (öffnet LinkedIn)') }}
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
import CertificateCard from './CertificateCard.vue'
import { getCertificate, downloadUrl, getCourseCertificate, courseDownloadUrl } from '../services/CertificateService.js'
import {
	decodeCredential,
	extractCertificateFields,
	buildVerifyUrl,
	buildLinkedInUrl,
} from '../utils/certificate-credential.js'
import qrcode from '../utils/qrcode-generator.js'
import { installCertificatePrintHook, printCertificate, removeCertificatePrintCopy } from '../utils/certificatePrint.js'

export default {
	name: 'Certificate',

	components: {
		CertificateCard,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		verificationId: {
			type: String,
			required: true,
		},
		/**
		 * GitHub #42: set by the compliance report. The certificate then loads and downloads
		 * through the course-scoped instructor endpoints (same data, same file), the LinkedIn
		 * action (the learner's own profile) is hidden, and a revoked or expired state is shown.
		 * null (default) is the learner's own view, unchanged.
		 */
		courseId: {
			type: Number,
			default: null,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			fields: null,
			// GitHub #43: the layout frozen at issuance; null = the standard card.
			presentation: null,
			qrDataUrl: '',
			revoked: false,
			expired: false,
			// GitHub #67: the course can switch "Add to LinkedIn" off; a missing field keeps it.
			linkedinAllowed: true,
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
			return this.courseId !== null
				? courseDownloadUrl(this.courseId, this.verificationId)
				: downloadUrl(this.verificationId)
		},
		statusNote() {
			if (this.courseId === null) {
				return ''
			}
			if (this.revoked) {
				return t('learning', 'Dieses Zertifikat wurde widerrufen und ist nicht mehr gültig.')
			}
			if (this.expired) {
				return t('learning', 'Dieses Zertifikat ist abgelaufen.')
			}
			return ''
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
		// GitHub #41: Ctrl+P / the browser's print menu print the card as well, not the clipped modal.
		this.disposePrintHook = installCertificatePrintHook(() => this.$refs.printable || null)
	},

	unmounted() {
		this.disposePrintHook?.()
		removeCertificatePrintCopy()
	},

	methods: {
		async load() {
			this.loading = true
			this.error = ''
			try {
				const row = this.courseId !== null
					? await getCourseCertificate(this.courseId, this.verificationId)
					: await getCertificate(this.verificationId)
				this.revoked = row?.revoked === true
				this.expired = row?.expired === true
				this.linkedinAllowed = row?.linkedin_allowed !== false
				const credential = decodeCredential(row.credential_json)
				if (!credential) {
					this.error = t('learning', 'Zertifikat konnte nicht gelesen werden.')
					return
				}
				this.fields = extractCertificateFields(credential)
				this.presentation = row?.presentation && typeof row.presentation === 'object' ? row.presentation : null
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

		// GitHub #41: print a copy outside the modal, which clipped the card to its own box.
		print() {
			return printCertificate(this.$refs.printable || null)
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

.certificate-status-note {
	margin: 0 0 12px;
	padding: 8px 12px;
	border-inline-start: 4px solid var(--color-element-error, var(--color-error));
	border-radius: var(--border-radius, 4px);
	background: var(--color-error);
	color: var(--color-error-text, var(--color-main-text));
	font-weight: 600;
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
/* Print stylesheet (CERT-07, GitHub #41). print() copies the card into
   #learning-certificate-print-root directly under <body> and sets
   html.learning-certificate-printing; only then does printing show the copy and nothing else.
   Printing the card inside NcModal clipped it to the modal box (almost empty sheet), and the
   old unscoped `body * { visibility: hidden }` also blanked every other print in the app.
   The A4 page is a named page used only by the print root: an unnamed @page in this global
   stylesheet forced A4 with 18mm margins onto every print in the app (reports, Klassenbuch). */
@page learning-cert {
	size: A4;
	margin: 18mm;
}

#learning-certificate-print-root {
	display: none;
}

@media print {
	html.learning-certificate-printing,
	html.learning-certificate-printing body {
		height: auto !important;
		min-height: 0 !important;
		overflow: visible !important;
		background: #fff !important;
	}

	html.learning-certificate-printing body > *:not(#learning-certificate-print-root) {
		display: none !important;
	}

	/* Paper is white in every theme: a dark-mode card would print light text on no background. */
	html.learning-certificate-printing #learning-certificate-print-root {
		display: block !important;
		page: learning-cert;
		position: static;
		width: 100%;
		--color-main-background: #fff;
		--color-main-text: #000;
		--color-text-maxcontrast: #555;
		--color-border: #ccc;
		color: #000;
		background: #fff;
	}

	/* Certificate-grade geometry: card fits the A4 printable area (210mm minus 2x18mm margin = 174mm); */
	/* border-box keeps the 32px padding INSIDE max-width so the outer box cannot overflow the page. */
	#learning-certificate-print-root .certificate-card {
		box-sizing: border-box;
		max-width: 165mm;
		margin: 0 auto;
		box-shadow: none;
		border: 1px solid #ccc;
		break-inside: avoid;
		page-break-inside: avoid;
	}

	/* Guaranteed scannable QR at a fixed physical size (not pixel-dependent). */
	#learning-certificate-print-root .certificate-qr {
		width: 30mm;
		height: 30mm;
	}

	/* The revoked / expired note keeps its meaning on white paper in every theme. */
	#learning-certificate-print-root .certificate-status-note {
		background: #fff;
		color: #8b0000;
		border: 2px solid #8b0000;
	}

	.no-print {
		display: none !important;
	}
}
</style>
