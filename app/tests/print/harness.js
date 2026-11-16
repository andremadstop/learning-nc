// GitHub #80: mounts the real Certificate.vue outside Nextcloud. The spec answers the certificate
// request (page.route) and stubs window.print, then prints the page to PDF.
import { createApp, h } from 'vue'
import Certificate from '../../src/components/Certificate.vue'

const interpolate = (text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
window.t = (app, text, vars) => interpolate(text, vars)
window.n = (app, one, many, count, vars) => interpolate(count === 1 ? one : many, { count, ...vars })
window.OC = { webroot: '', config: { modRewriteWorking: false }, theme: { name: 'Harness' } }
window._oc_webroot = ''

// ?course=<id>: the instructor's copy from the compliance report (shows the revoked/expired note)
const course = new URLSearchParams(window.location.search).get('course')
const app = createApp({
	render: () => h(Certificate, {
		verificationId: '11111111-2222-4333-8444-555555555555',
		courseId: course ? Number(course) : null,
	}),
})
app.config.globalProperties.t = window.t
app.config.globalProperties.n = window.n
app.mount('#app')
