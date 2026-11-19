// GitHub #80: mounts the real Certificate.vue outside Nextcloud. The spec answers the certificate
// request (page.route) and stubs window.print, then prints the page to PDF.
import { createApp, h } from 'vue'
import Certificate from '../../src/components/Certificate.vue'
import { buildCertificateSheet, noteParts, removeCertificatePdfCopy } from '../../src/utils/certificatePdf.js'

const params = new URLSearchParams(window.location.search)

// GitHub #94: ?lang=ar|ru|… uses the app's real catalogue and the interface direction of that language
const catalogues = import.meta.glob('../../l10n/*.json', { eager: true, import: 'default' })
const lang = params.get('lang')
const catalogue = lang ? (catalogues['../../l10n/' + lang + '.json']?.translations ?? {}) : {}
if (lang) {
	document.documentElement.setAttribute('lang', lang)
	document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr')
}

const interpolate = (text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
const translated = (text) => (typeof catalogue[text] === 'string' && catalogue[text] !== '' ? catalogue[text] : text)
window.t = (app, text, vars) => interpolate(translated(text), vars)
window.n = (app, one, many, count, vars) => interpolate(translated(count === 1 ? one : many), { count, ...vars })
window.OC = { webroot: '', config: { modRewriteWorking: false }, theme: { name: 'Harness' } }
window._oc_webroot = ''

// for the PDF spec: the same sheet the PDF is drawn from, shown on screen for a pixel comparison
window.__certificatePdf = { buildCertificateSheet, noteParts, removeCertificatePdfCopy }

// ?course=<id>: the instructor's copy from the compliance report (shows the revoked/expired note)
const course = params.get('course')
const app = createApp({
	render: () => h(Certificate, {
		verificationId: '11111111-2222-4333-8444-555555555555',
		courseId: course ? Number(course) : null,
	}),
})
app.config.globalProperties.t = window.t
app.config.globalProperties.n = window.n
app.mount('#app')
