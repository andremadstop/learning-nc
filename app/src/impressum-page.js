import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ImprintPage from './components/ImpressumPage.vue'
import { parseOperatorAttribute } from './utils/operatorInfo.js'

const mountNode = document.getElementById('learning-imprint-page')
const appUrl = mountNode?.dataset?.appUrl || ''
const privacyUrl = mountNode?.dataset?.privacyUrl || ''
// the operator an admin entered (server-rendered); null = none, the page shows a hint
const operator = parseOperatorAttribute(mountNode?.dataset?.operator)
const app = createApp(ImprintPage, {
	appUrl,
	privacyUrl,
	operator,
})

app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#learning-imprint-page')
