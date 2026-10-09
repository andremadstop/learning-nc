import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import '@nextcloud/dialogs/style.css'
import PrivacyPage from './components/PrivacyPage.vue'

const mountNode = document.getElementById('learning-privacy-page')
const appUrl = mountNode?.dataset?.appUrl || ''
const imprintUrl = mountNode?.dataset?.imprintUrl || ''
// Who receives AI data right now (server-rendered, without any server address); null = unknown
let aiNotice = null
try {
	const parsed = JSON.parse(mountNode?.dataset?.aiNotice || 'null')
	aiNotice = parsed && typeof parsed === 'object' ? parsed : null
} catch (e) {
	aiNotice = null
}
const app = createApp(PrivacyPage, {
	appUrl,
	imprintUrl,
	aiNotice,
})

app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#learning-privacy-page')
