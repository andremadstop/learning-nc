import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import '@nextcloud/dialogs/style.css'
import ImprintPage from './components/ImpressumPage.vue'

const mountNode = document.getElementById('learning-imprint-page')
const appUrl = mountNode?.dataset?.appUrl || ''
const privacyUrl = mountNode?.dataset?.privacyUrl || ''
const app = createApp(ImprintPage, {
	appUrl,
	privacyUrl,
})

app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#learning-imprint-page')
