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
				<!-- GitHub #42/#94: the card says when it no longer counts, on screen, on paper and in the PDF. -->
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
				<!-- GitHub #94: a PDF of the printed sheet, made in the browser, with a presentation-copy line -->
				<NcButton
					variant="secondary"
					:disabled="pdfBusy"
					:aria-busy="pdfBusy ? 'true' : 'false'"
					data-test="certificate-pdf-button"
					@click="downloadPdf">
					{{ t('learning', 'Als PDF herunterladen') }}
				</NcButton>
				<NcButton v-if="courseId === null && linkedinAllowed" variant="secondary" @click="openLinkedIn">
					{{ t('learning', 'Zu LinkedIn hinzufügen (öffnet LinkedIn)') }}
				</NcButton>
			</div>

			<!-- always rendered, only the text changes: a live region added later is not announced -->
			<p class="certificate-pdf-status no-print" role="status" data-test="certificate-pdf-status">{{ pdfStatus }}</p>
			<NcNoteCard
				v-if="pdfError"
				type="error"
				class="no-print"
				data-test="certificate-pdf-error">
				{{ pdfError }}
			</NcNoteCard>

			<!-- GitHub #95: the signed credential file confused learners (a page of code, no next step).
				It sits behind a closed native disclosure (keyboard and screen readers get the open/closed
				state for free) with a name and one sentence on what it is for. The course can hide it
				for learners; the instructor's copy always shows it. Same URL and file as before. -->
			<details v-if="credentialSectionShown" class="certificate-credential no-print">
				<summary class="certificate-credential-summary">
					{{ t('learning', 'Technische Details') }}
				</summary>
				<div class="certificate-credential-body">
					<p class="certificate-credential-title">
						{{ t('learning', 'Digitaler Nachweis für IT-Systeme (JSON)') }}
					</p>
					<p class="certificate-credential-text">
						{{ t('learning', 'Eine signierte, maschinenlesbare Fassung dieses Zertifikats (W3C Verifiable Credential) für Systeme, die digitale Nachweise prüfen oder importieren. Zum Vorzeigen genügt das Zertifikat oder das PDF.') }}
					</p>
					<a
						:href="downloadHref"
						class="certificate-download-link"
						download>
						<NcButton variant="secondary" @click.prevent="download">
							{{ t('learning', 'Nachweis herunterladen (JSON)') }}
						</NcButton>
					</a>
				</div>
			</details>
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
import {
	canDownloadByLink,
	certificatePdfFileName,
	noteParts,
	removeCertificatePdfCopy,
	renderCertificatePdf,
	savePdf,
} from '../utils/certificatePdf.js'

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
			// unix seconds (learner response); the instructor response says `expired` itself
			expiresAt: null,
			// GitHub #67: the course can switch "Add to LinkedIn" off; a missing field keeps it.
			linkedinAllowed: true,
			// GitHub #95: the course can hide the credential section for learners; a missing field shows it.
			credentialDownloadAllowed: true,
			// GitHub #94
			pdfBusy: false,
			pdfStatus: '',
			pdfError: '',
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
		// GitHub #95: instructors and administrators (the course-scoped copy) always see it.
		credentialSectionShown() {
			return this.courseId !== null || this.credentialDownloadAllowed
		},
		/**
		 * GitHub #42: the instructor's copy says when it no longer counts. GitHub #94: the learner's
		 * own card too, since learners keep opening expired certificates and the PDF of one must not
		 * look like a valid certificate. Expired comes from the response, else from the expiry date.
		 */
		statusNote() {
			if (this.revoked) {
				return t('learning', 'Dieses Zertifikat wurde widerrufen und ist nicht mehr gültig.')
			}
			if (this.isExpired) {
				return t('learning', 'Dieses Zertifikat ist abgelaufen.')
			}
			return ''
		},
		isExpired() {
			if (this.expired) {
				return true
			}
			const now = Date.now()
			if (typeof this.expiresAt === 'number' && this.expiresAt > 0) {
				return this.expiresAt * 1000 < now
			}
			const until = this.fields?.validUntil ? new Date(this.fields.validUntil).getTime() : NaN
			return !Number.isNaN(until) && until < now
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
		removeCertificatePdfCopy()
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
				this.expiresAt = typeof row?.expires_at === 'number' ? row.expires_at : null
				this.linkedinAllowed = row?.linkedin_allowed !== false
				this.credentialDownloadAllowed = row?.credential_download_allowed !== false
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

		/**
		 * GitHub #94: never silent. While the PDF is made the button is busy and the status says so;
		 * a failure says what to do instead. Where links cannot save files (old iOS web views) the
		 * tab for the PDF is opened now, inside the click: opened after the wait it would be blocked.
		 */
		async downloadPdf() {
			if (this.pdfBusy) {
				return
			}
			const source = this.$refs.printable || null
			const pendingWindow = canDownloadByLink() ? null : window.open('', '_blank')
			this.pdfBusy = true
			this.pdfError = ''
			this.pdfStatus = t('learning', 'PDF wird erstellt …')
			try {
				const root = document.documentElement
				const blob = await renderCertificatePdf(source, {
					note: noteParts(
						t('learning', 'Präsentationskopie, kein eigenständiger Nachweis. Echtheit prüfen: QR-Code scannen oder {url} öffnen (Zertifikat-ID {id}).'),
						{ id: this.verificationId, url: this.verifyUrl },
					),
					title: this.fields?.courseTitle || '',
					subject: this.verifyUrl,
					dir: root.getAttribute('dir') || (typeof getComputedStyle === 'function' && source ? getComputedStyle(source).direction : ''),
					lang: root.getAttribute('lang') || '',
				})
				savePdf(blob, certificatePdfFileName({
					prefix: t('learning', 'Zertifikat'),
					courseTitle: this.fields?.courseTitle || '',
					validFrom: this.fields?.validFrom,
				}), { pendingWindow })
				this.pdfStatus = t('learning', 'PDF heruntergeladen')
			} catch (e) {
				pendingWindow?.close()
				this.pdfStatus = ''
				this.pdfError = t('learning', 'PDF konnte nicht erstellt werden. Bitte „Drucken“ verwenden.')
			} finally {
				this.pdfBusy = false
			}
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

.certificate-pdf-status {
	margin: -8px 0 0;
	min-height: 1.4em;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.certificate-pdf-status:empty {
	/* keeps the live region in the page without an empty gap */
	min-height: 0;
	margin: -20px 0 0;
}

.certificate-download-link {
	display: inline-block;
	text-decoration: none;
}

.certificate-credential {
	align-self: center;
	width: 100%;
	max-width: 640px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 0 12px;
}

.certificate-credential-summary {
	/* the native marker shows the open/closed state; the padding makes the row a 44 px target */
	cursor: pointer;
	padding: 12px 0;
	color: var(--color-main-text);
	font-weight: 600;
}

.certificate-credential-summary:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.certificate-credential-body {
	padding: 0 0 12px;
}

.certificate-credential-title {
	margin: 0 0 4px;
	font-weight: 600;
}

.certificate-credential-text {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}
</style>

<style>
/* Print stylesheet (CERT-07, GitHub #41, #80). print() copies the card into
   #learning-certificate-print-root directly under <body> and sets
   html.learning-certificate-printing; only then does printing show the copy and nothing else.
   Printing the card inside NcModal clipped it to the modal box (almost empty sheet), and the
   old unscoped `body * { visibility: hidden }` also blanked every other print in the app.
   The page is a named page used only by the print root: an unnamed @page in this global
   stylesheet forced its size and margins onto every print in the app (reports, Klassenbuch).

   GitHub #80: the card was capped at 165 mm on portrait A4 and came out about 16 × 12.5 cm. A
   certificate is landscape: A4 landscape by default with fixed 12 mm margins, the card as wide as
   the printable area and at least most of its height, so it fills the sheet. Its own spacing is in
   millimetres on paper, so the fullest layout (text, course elements, stamp, signature, signer)
   still fits one landscape page. When the printer's paper wins (Letter, or portrait in Firefox),
   the card takes that page's full width instead: nothing is tied to A4 but the default. */
@page learning-cert {
	size: A4 landscape;
	margin: 12mm;
}

#learning-certificate-print-root {
	display: none;
}

/* GitHub #94: the printed sheet and the PDF are the same sheet. Every rule that turns the card into
   an A4 landscape certificate (grid, millimetre sizes, paper colours, the 22 mm QR code, the status
   note on white) belongs to .certificate-sheet, which both the print copy and the PDF copy carry.
   Only page and visibility rules stay in @media print. The PDF is drawn from screen media, so with
   these rules inside @media print the PDF would show the stretched screen card instead.
   Each rule starts with .certificate-sheet .certificate-printable: one class more than the card's
   own scoped rules (class + attribute), so the sheet wins as the id selector did before. */
.certificate-sheet {
	--color-main-background: #fff;
	--color-main-text: #000;
	--color-text-maxcontrast: #555;
	--color-border: #ccc;
	color: #000;
	background: #fff;
	print-color-adjust: exact;
	-webkit-print-color-adjust: exact;
}

.certificate-sheet .certificate-printable {
	width: 100%;
}

/* The card fills the printable width; its height stays below the shortest landscape page
	(A4: 210 mm − 2 × 12 mm = 186 mm), with room for the revoked/expired note above it, so it never
	spills onto a second sheet by a few millimetres.
	border-box keeps padding and border inside. */
.certificate-sheet .certificate-printable .certificate-card {
	box-sizing: border-box;
	width: 100%;
	max-width: none;
	min-height: 160mm;
	margin: 0;
	padding: 6mm 14mm;
	/* a grid instead of the screen's column: the sign-off and the verification block share the
	bottom row, as on a printed certificate, which keeps the fullest layout on one page */
	display: grid;
	grid-template-columns: 1fr 1fr;
	column-gap: 10mm;
	row-gap: 3mm;
	align-content: center;
	box-shadow: none;
	/* the frame of the screen card, in the instance's primary colour */
	border: 0.6mm solid var(--color-primary-element, #1a3a5c);
	border-radius: 3mm;
	break-inside: avoid;
	page-break-inside: avoid;
	font-size: 11pt;
	line-height: 1.3;
}

.certificate-sheet .certificate-printable .certificate-card > * {
	grid-column: 1 / -1;
}

/* GitHub #93: the background layer is no grid item: without this it would be placed in the
	grid area of 1 / -1 and stop at the card's padding instead of filling the sheet */
.certificate-sheet .certificate-printable .certificate-card > .certificate-bg {
	grid-column: auto;
	grid-row: auto;
}

.certificate-sheet .certificate-printable .certificate-card > .certificate-signoff {
	grid-column: 1;
	align-self: end;
}

.certificate-sheet .certificate-printable .certificate-card > .certificate-signoff + .certificate-verify {
	grid-column: 2;
	align-self: end;
	justify-content: flex-end;
}

.certificate-sheet .certificate-printable .certificate-card p,
.certificate-sheet .certificate-printable .certificate-card li {
	line-height: 1.3;
}

.certificate-sheet .certificate-printable .certificate-logo {
	max-height: 16mm;
	max-width: 60mm;
}

.certificate-sheet .certificate-printable .certificate-issuer {
	font-size: 13pt;
}

.certificate-sheet .certificate-printable .certificate-title {
	font-size: 22pt;
}

.certificate-sheet .certificate-printable .certificate-meta {
	gap: 10mm;
	margin-top: 1mm;
}

.certificate-sheet .certificate-printable .certificate-signoff {
	margin-top: 1mm;
}

.certificate-sheet .certificate-printable .certificate-stamp {
	max-height: 18mm;
	max-width: 34mm;
}

.certificate-sheet .certificate-printable .certificate-signature {
	max-height: 15mm;
	max-width: 55mm;
}

.certificate-sheet .certificate-printable .certificate-verify {
	margin-top: 1mm;
	padding-top: 3mm;
}

/* Guaranteed scannable QR at a fixed physical size (not pixel-dependent). */
.certificate-sheet .certificate-printable .certificate-qr {
	width: 22mm;
	height: 22mm;
}

/* GitHub #93: the white plate around the QR code, in millimetres on paper (≥ two more modules) */
.certificate-sheet .certificate-printable .certificate-card--background .certificate-qr {
	padding: 2mm;
}

/* The revoked / expired note keeps its meaning on white paper in every theme. */
.certificate-sheet .certificate-printable .certificate-status-note {
	margin: 0 0 3mm;
	padding: 1.5mm 3mm;
	font-size: 10pt;
	background: #fff;
	color: #8b0000;
	border: 2px solid #8b0000;
}

/* GitHub #94: the PDF copy is the sheet itself, A4 landscape with the print margins, outside the
   viewport (never display: none, or there is nothing to draw). certificatePdf.js resets the
   position in the picture. The presentation-copy line sits in the bottom margin, below the card. */
.certificate-sheet--pdf {
	position: fixed;
	top: 0;
	left: 0;
	transform: translateX(calc(-100% - 200px));
	box-sizing: border-box;
	width: 1123px;
	height: 794px;
	margin: 0;
	padding: 12mm;
	overflow: hidden;
	pointer-events: none;
}

.certificate-sheet--pdf .certificate-pdf-note {
	position: absolute;
	inset-inline: 12mm;
	bottom: 3.5mm;
	margin: 0;
	font-size: 8pt;
	line-height: 1.3;
	text-align: center;
	color: #444;
}

@media print {
	html.learning-certificate-printing,
	html.learning-certificate-printing body {
		height: auto !important;
		min-height: 0 !important;
		margin: 0 !important;
		padding: 0 !important;
		overflow: visible !important;
		background: #fff !important;
	}

	html.learning-certificate-printing body > *:not(#learning-certificate-print-root) {
		display: none !important;
	}

	/* Paper is white in every theme (.certificate-sheet): a dark-mode card would print light text on no background. */
	html.learning-certificate-printing #learning-certificate-print-root {
		display: block !important;
		page: learning-cert;
		position: static;
		width: 100%;
		margin: 0;
		padding: 0;
	}

	.no-print {
		display: none !important;
	}
}
</style>
