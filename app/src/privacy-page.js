import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import '@nextcloud/dialogs/style.css'
import PrivacyPage from './components/PrivacyPage.vue'

const mountNode = document.getElementById('learning-privacy-page')
const appUrl = mountNode?.dataset?.appUrl || ''
const imprintUrl = mountNode?.dataset?.imprintUrl || ''
const app = createApp(PrivacyPage, {
	appUrl,
	imprintUrl,
})

app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#learning-privacy-page')
